<?php
set_time_limit(600);

use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\Client;

// load required files
require __DIR__ . '/../vendor/autoload.php';

// initialize application
$settings = require '../src/settings.php';

// if BlueMix VCAP_SERVICES environment available
// overwrite with credentials from BlueMix
if ($services = getenv("VCAP_SERVICES")) {
  $services_json = json_decode($services, true);
  
  $settings['settings']['object-store']['url'] = $services_json["Object-Storage"][0]["credentials"]["auth_url"] . '/v3';
  $settings['settings']['object-store']['region'] = $services_json["Object-Storage"][0]["credentials"]["region"];
  $settings['settings']['object-store']['user'] = $services_json["Object-Storage"][0]["credentials"]["userId"];
  $settings['settings']['object-store']['pass'] = $services_json["Object-Storage"][0]["credentials"]["password"];
  $settings['settings']['indexer']['url'] = $services_json["searchly"][0]["credentials"]["uri"];
} 

$app = new \Slim\App($settings);

// configure dependencies
$container = $app->getContainer();

// view renderer
$container['renderer'] = function ($c) {
  $config = $c->get('settings');
  return new Slim\Views\PhpRenderer($config['renderer']['template_path']);
};

// pdf parser
$container['pdfparser'] = function ($c) {
  return new Smalot\PdfParser\Parser();
};

// indexer
$container['indexer'] = function ($c) {
  $config = $c->get('settings');
  $params['hosts'] = array($config['indexer']['url'] . ':80');
  return new Elasticsearch\Client($params);
};

$container['objectstorage'] = function ($c) {
  $config = $c->get('settings');
  $openstack = new OpenStack\OpenStack(array(
    'authUrl' => $config['object-store']['url'],
    'region'  => $config['object-store']['region'],
    'user'    => array(
      'id'       => $config['object-store']['user'],
      'password' => $config['object-store']['pass']
  )));
  return $openstack->objectStoreV1();
};

$app->get('/', function ($request, $response, $args) {
  return $response->withStatus(301)->withHeader('Location', 'index');
});

$app->get('/index', function ($request, $response, $args) {
  return $this->renderer->render($response, 'index.phtml', array('router' => $this->router));
})->setName('index');

$app->get('/add', function ($request, $response, $args) {
  return $this->renderer->render($response, 'add.phtml', array('router' => $this->router));
})->setName('add');

$app->post('/add', function ($request, $response, $args) {
  
  $post = $request->getParsedBody();
  $files = $request->getUploadedFiles();
  
  try {
  
    // check for valid inputs
    if (empty($post['name'])) {
      throw new Exception('No name provided');
    }

    if (empty($post['email']) || (filter_var($post['email'], FILTER_VALIDATE_EMAIL) == false)) {
      throw new Exception('Invalid email address provided');
    }

    if (!empty($post['url']) && (filter_var($post['url'], FILTER_VALIDATE_URL) == false)) {
      throw new Exception('Invalid URL provided');
    }
        
    // check for valid file upload
    if (empty($files['upload']->getClientFilename())) {
      throw new Exception('No file uploaded');
    }
    
    // check for valid file type
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $type = $finfo->file($files['upload']->file);
    if ($type != 'application/pdf') {
      throw new Exception('Invalid file format, only PDF supported');    
    }
    
    // extract text from PDF
    $pdf = $this->pdfparser->parseFile($files['upload']->file);
    $text = $pdf->getText();
    
    // add text to index
    $document = array(
        'name' => strip_tags($post['name']),
        'email' => strip_tags($post['email']),
        'content' => $text,
        'url' => strip_tags($post['url']),
        'notes' => strip_tags($post['notes']),   
     );
     
    $params = array();
    $params['body']  = $document;
    $params['index'] = 'cvs';
    $params['type']  = 'doc';
    $indexerResponse = $this->indexer->index($params);
    $id = $indexerResponse['_id'];

    // save PDF to object store 
    $container = $this->objectstorage->getContainer('cvs');
    $stream = new Stream(fopen($files['upload']->file, 'r'));
    $options = array(
      'name'   => trim("$id.pdf"),
      'stream' => $stream,
    );
    $container->createObject($options);

    return $this->renderer->render($response, 'add.phtml', array('router' => $this->router, 'id' => $id));
    
  } catch (ClientException $e) {
    throw new Exception($e->getResponse());
  }
});

$app->get('/search', function ($request, $response, $args) {
  $params = $request->getQueryParams();
  $hits = array();
  $q = '';
  if (isset($params['q'])) {
    $q = trim(strip_tags($params['q']));
    if (!empty($q)) {
      $search = [
        'index' => 'cvs',
        'type' => 'doc',
        'body' => [
          'query' => [
            'multi_match' => [
              'query' => $q,
              'fields' => ['content', 'notes']
            ]
          ],
          'highlight' => [
            'fields' => [
              'content' => [
                'type' => 'plain',
                'fragment_size' => 40,
                'number_of_fragments' => 1
              ],
              'notes' => [
                'type' => 'plain',
                'fragment_size' => 40,
                'number_of_fragments' => 1
              ],                
            ]
          ]
        ]
      ];
      $results = $this->indexer->search($search);
      if ($results['hits']['total'] > 0) {
        $hits = $results['hits']['hits'];
      }
    }
  }  
  return $this->renderer->render($response, 'search.phtml', array('router' => $this->router, 'hits' => $hits, 'q' => $q));
})->setName('search');

$app->get('/download/{id}', function ($request, $response, $args) {
  $service = $this->objectstorage;
  $id = trim(strip_tags($args['id'])); 
  $stream = $service->getContainer('cvs')
                  ->getObject("$id.pdf")
                  ->download();
  /*
  $response = $response->withHeader('Content-type', 'application/pdf')
                       ->withHeader('Content-Disposition', 'attachment; filename="' . "$id.pdf" .'"')
                       ->withHeader('Content-Length', $stream->getSize())
                       ->withHeader('Expires', '@0')
                       ->withHeader('Cache-Control', 'must-revalidate')
                       ->withHeader('Pragma', 'public');
  $response = $response->withBody($stream);
  return $response;
  */
  header("Content-Disposition: attachment; filename=$id.pdf");
  header('Content-Type: application/pdf');
  header('Cache-Control: must-revalidate');
  header('Pragma: public');
  header('Content-Length: ' . $stream->getSize());
  ob_clean();
  flush();
  echo $stream;
})->setName('download');

$app->get('/legal', function ($request, $response, $args) {
  return $this->renderer->render($response, 'legal.phtml', array('router' => $this->router));
})->setName('legal');

$app->get('/reset', function ($request, $response, $args) {
  $params = [
    'index' => 'cvs'
  ];
  $this->indexer->indices()->delete($params);
  $this->indexer->indices()->create($params);
  $container = $this->objectstorage->getContainer('cvs');
  foreach ($container->listObjects() as $object) {
    $object->containerName = 'cvs';
    $object->delete();
  }
  $container->delete();
  $this->objectstorage->createContainer(array(
    'name' => 'cvs'
  )); 
  return $response->withStatus(301)->withHeader('Location', 'index');
})->setName('reset');

// run app
$app->run();
