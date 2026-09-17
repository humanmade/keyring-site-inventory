<?php
/**
 * Plugin Name: Keyring Site Inventory
 * Description: Read-only WordPress site-user roster for Keyring.
 * Author: Human Made
 * Author URI: https://humanmade.com/
 * Version: 0.2.0
 * Text Domain: keyring-site-inventory
 */

namespace HM\Keyring;

require_once __DIR__ . '/inc/namespace.php';
require_once __DIR__ . '/inc/sites.php';
require_once __DIR__ . '/inc/class-inventory-controller.php';
require_once __DIR__ . '/inc/class-users-controller.php';
require_once __DIR__ . '/inc/class-sites-controller.php';

Site_Inventory\bootstrap();
