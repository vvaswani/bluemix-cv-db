# CV Database TEST

This repository accompanies the IBM developerWorks article. It's built with PHP, Slim 3.x and Bootstrap. It uses various services, including Bluemix Object Storage and Searchly. 

It delivers an application that lets users upload CVs in PDF format and index those CVs for search.

The steps below assume that an Object Storage service and a Searchly service have been instantiated via the the Bluemix console.

To deploy this application to your local development environment:

 * Clone the repository to your local system.
 * Run `composer update` to install all dependencies.
 * Create `settings.php` with credentials for the various services. Use `settings.php.sample` as an example.
 * Create an empty index named `cvs` in your Searchly instance.
 * Create an empty container named `cvs` in your Object Storage instance.
 * Define a virtual host pointing to the `public` directory, as described in the article.
 
To deploy this application to your Bluemix space:

 * Clone the repository to your local system.
 * Run `composer update` to install all dependencies.
 * Create `settings.php` with credentials for the various services. Use `settings.php.sample` as an example.
 * Create an empty index named `cvs` in your Searchly instance.
 * Create an empty container named `cvs` in your Object Storage instance.
 * Update `manifest.yml` with your custom hostname.
 * Push the application to Bluemix and bind Object Storage and Searchly services to it, as described in the article.
 
A demo instance is available on Bluemix at [http://cvdb.mybluemix.net](http://cvdb.mybluemix.net).

###### NOTE: The demo instance is available on a public URL and you should be careful to avoid posting sensitive or confidential documents to it. Use the "System Reset" function on the "Legal" page to delete all data and restore the system to its default state.
