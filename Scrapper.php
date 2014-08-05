<?php
/**
 * Created by PhpStorm.
 * User: Vishal
 * Date: 5/08/2014
 * Time: 2:48 PM
 */

$path = __FILE__;
require_once(dirname($path) . "/lib/log4php/Logger.php");
require_once(dirname($path) . "/lib/scrapper.functions.php");
require_once(dirname($path) . "/lib/redbeanphp/rb.php");

define('C_HOST','localhost');// MySQL host name (usually:localhost)
define('C_USER','root');// MySQL username
define('C_PASS','root');// MySQL password
define('C_BASE','price');// MySQL database

define('SCRAPPER_INCLUDE', true);

Logger::configure('log4php.xml');
global $logger, $verbose, $debug, $config, $siteKeyId;
$logger = Logger::getLogger('default');
$verbose = Logger::getLogger('verbose');

// Special Autoload functions
spl_autoload_register('load_models');
spl_autoload_register('load_lib');


$options = getopt("c::", array("configuration::"));

// Name or path to default configuration file here
$default_config_file = "configuration.php";

$config_file = (isset($options['configuration']) && !empty($options['configuration'])) ? $options['configuration'] : $default_config_file;
// Include the configuration
$config = is_file($config_file);

// Load the configuration file
if (!$config) {
    $logger->error("Configuration File not found in path: " . $config_file);
    end_scrapper();
} else {
    $config = require($config_file);
    $debug = (isset($config['_verbose'])) ? (bool)$config['_verbose'] : true;
}

// Connect the RedbeansPHP to Datastore
R::addDatabase('production', $config['_datastore']['dsn'], $config['_datastore']['user'], $config['_datastore']['pass'], false); // Make the production frozen, so that RedBean does not change the schema on demand
// Make sure the configuration actually requested for the default datastore and it contains values in it
if (isset($config['_default_datastore']) && count($config['_default_datastore']) >= 3) {
    R::addDatabase('default', $config['_default_datastore']['dsn'], $config['_default_datastore']['user'], $config['_default_datastore']['pass'], false);
}
R::selectDatabase('production'); // Select the production datastore by default


if (isset($config['sites'])) {
    if (count($config['sites']) < 1) {
        verbose("There are no sites defined for crawling.");
        $logger->warn('There are no sites defined for crawling.');
    } else {
        while ($site = current($config['sites'])) {
            // Now start the scraphp which will crawl the websites
            $scraphp = new ScrapperSpider;
            // Set the general configuration for the scraphp
            $scraphp->configure($config);
            verbose("Scrapper Scraphp has been created and configured");

            // Key used for refering to the site
            $siteKey = strtolower(key($config['sites']));
            verbose("Starting to process site $siteKey");
            // Add the key to the pricesource

//            try{
//
//                $dsn = 'mysql:dbname='.C_BASE.';host='.C_HOST;
//                $dbh = new PDO($dsn, C_USER, C_PASS);
//                $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
//                return $dbh;
//
////            $pdo = new PDO(
////               'mysql:host=localhost:1234;dbname=price',
////               'root',
////               'root',
////                array(1002 => 'SET NAMES utf8',
////                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
////                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
////                )
////            );
//            }
//            catch(PDOException $err)
//            {
//                echo $err->getMessage();
//            }
            $sourceExist = R::findOne('pricesource', 'name = ?', array($siteKey));
            if (is_null($sourceExist)) {
                $priceSource = R::dispense('pricesource');
                $priceSource->name = $siteKey;
                $siteKeyId = R::store($priceSource); // Now that saves it all
            } else {
                $logger->trace("Source Key exist, and the key id is " . $sourceExist->id);
                $siteKeyId = $sourceExist->id; // Global value used across classes @TODO Can improve this?
            }

            if (!isset($site['base_url'])) {
                verbose("`base_url` is not defined for the site - $siteKey in the configuration", "fatal");
                $logger->fatal("`base_url` is not defined for the site - $siteKey in the configuration");
                end_scrapper();
            }
            $baseUrl = $site['base_url'];
            verbose("Added the base URL as $baseUrl");

            // Setting the default URL to crawl in the scraphp
            $scraphp->setURL($baseUrl);

            if (isset($site['validMatch'])) {
                $scraphp->addFollowMatch(trim($site['validMatch']));
                verbose("Added the FollowMatch Pattern as " . $site['validMatch']);
            } else {
                verbose("No FollowMatch Pattern were added to the Scraphp.");
            }

            if (isset($site['nonValidMatch'])) {
                $scraphp->addNonFollowMatch(trim($site['validMatch']));
                verbose("Added the NonFollowMatch Pattern as " . $site['nonValidMatch']);
            } else {
                verbose("No NonFollowMatch Pattern were added to the Scraphp.");
            }

            if (isset($site['pageLimit'])) {
                $scraphp->setPageLimit(trim($site['pageLimit']));
                verbose("Added the PageLimit value as " . $site['pageLimit']);
            } else {
                verbose("No PageLimit value were added to the Scraphp.");
            }

            // Set the siteConfig in the Scraphp to access the site configuration for processing
            $scraphp->siteConfig = $site;

            verbose("Starting the crawler now...");
            // That's it, start the Scraphp..
            // Go.. Go.. Go..
            $scraphp->go();

            // Phew! We are done with $site start processing the next site
            next($config['sites']);
        }
    }
} else {
    $logger->error("'sites' key is not defined in the configuration. Refer documentation for more details.");
    verbose("'sites' key is not defined in the configuration. Refer documentation for more details.", "fatal");
    exit(1);
}
