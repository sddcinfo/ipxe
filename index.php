<?php
// /srv/http/index.php

// --- Configuration ---
define('SERVER_IP', '10.10.1.1');
define('STATE_FILE', __DIR__ . '/state.json');
define('CONFIG_DIR', __DIR__ . '/autoinstall_configs');
define('SESSION_DIR', __DIR__ . '/sessions'); // MUST BE WRITABLE by web server
define('ISO_BASE_URL', 'http://' . SERVER_IP . '/provisioning/ubuntu24.04');
define('ISO_NAME', 'ubuntu-24.04.2-live-server-amd64.iso');
// --- End Configuration ---


// --- Utility & State Management ---

/**
 * Validates a MAC address format.
 * @param string $mac
 * @return bool
 */
function is_valid_mac(string $mac): bool {
    return (bool)filter_var($mac, FILTER_VALIDATE_MAC);
}

/**
 * Reads the state database.
 * @return array
 */
function read_state_db(): array {
    if (!file_exists(STATE_FILE)) return [];
    $jsonData = file_get_contents(STATE_FILE);
    return $jsonData ? json_decode($jsonData, true) : [];
}

/**
 * Gets the status for a MAC address.
 * @param string $mac
 * @return string
 */
function get_status(string $mac): string {
    $db = read_state_db();
    return $db[strtolower($mac)]['status'] ?? 'NEW';
}

/**
 * Atomically sets the status for a MAC address.
 * @param string $mac
 * @param string $status
 * @return bool
 */
function set_status(string $mac, string $status): bool {
    $mac = strtolower($mac);
    $fp = fopen(STATE_FILE, 'c+');
    if (!$fp) { error_log("Failed to open state file: " . STATE_FILE); return false; }
    if (flock($fp, LOCK_EX)) {
        $raw_data = stream_get_contents($fp);
        $db = json_decode($raw_data, true) ?: [];
        $db[$mac] = ['status' => $status, 'timestamp' => date('c')];
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($db, JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }
    fclose($fp);
    error_log("Failed to acquire lock on state file: " . STATE_FILE);
    return false;
}


// --- Action Handlers ---

/**
 * Prepares the session directory with user-data, meta-data, and vendor-data.
 * This function performs template substitution for user-data and ensures
 * a vendor-data file is always present, creating an empty one if needed.
 *
 * @param string $mac The client's MAC address.
 * @return bool True on success, false on failure.
 */
function prepare_session(string $mac): bool {
    $mac_config_dir = CONFIG_DIR . '/' . $mac;
    $default_config_dir = CONFIG_DIR . '/default';
    $source_dir = is_dir($mac_config_dir) ? $mac_config_dir : $default_config_dir;

    if (!is_dir($source_dir)) {
        error_log("No config source directory found for MAC {$mac}");
        return false;
    }
    
    $session_path = SESSION_DIR . '/' . $mac;
    // Clean up old session if it exists
    if (is_dir($session_path)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($session_path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $fileinfo) { ($fileinfo->isDir() ? 'rmdir' : 'unlink')($fileinfo->getRealPath()); }
        rmdir($session_path);
    }

    if (!mkdir($session_path, 0755, true)) {
        error_log("Failed to create session directory: {$session_path}");
        return false;
    }

    // 1. Process user-data (template substitution)
    $user_data_template_path = $source_dir . '/user-data';
    if (file_exists($user_data_template_path)) {
        $template_content = file_get_contents($user_data_template_path);
        // Replace placeholders with actual values
        $final_content = str_replace('__MAC_ADDRESS__', $mac, $template_content);
        file_put_contents($session_path . '/user-data', $final_content);
    }

    // 2. Process meta-data (direct copy)
    $meta_data_template_path = $source_dir . '/meta-data';
    if (file_exists($meta_data_template_path)) {
        copy($meta_data_template_path, $session_path . '/meta-data');
    }

    // 3. Process vendor-data (copy if exists, otherwise create an empty file)
    $vendor_data_template_path = $source_dir . '/vendor-data';
    $vendor_data_destination_path = $session_path . '/vendor-data';
    if (file_exists($vendor_data_template_path)) {
        copy($vendor_data_template_path, $vendor_data_destination_path);
    } else {
        // If no vendor-data template exists, create a valid, empty one.
        $empty_content = "#cloud-config\n# This file was intentionally generated empty.\n";
        file_put_contents($vendor_data_destination_path, $empty_content);
    }
    
    return true;
}

/**
 * Handles the main 'boot' action for iPXE.
 */
function handle_boot(): void {
    $mac = strtolower($_GET['mac'] ?? '');
    if (!is_valid_mac($mac)) {
        header("HTTP/1.1 400 Bad Request");
        echo "Invalid or missing MAC address.";
        return;
    }

    header("Content-Type: text/plain");
    $status = get_status($mac);

    if ($status === 'DONE') {
        echo "#!ipxe\n";
        echo "echo Installation is DONE for {$mac}. Booting from local disk.\n";
        echo "exit\n";
    } else {
        // Prepare the session files for cloud-init
        if (!prepare_session($mac)) {
            echo "#!ipxe\n";
            echo "echo ERROR: Could not prepare installation session for {$mac}. Check server logs.\n";
            echo "reboot\n";
            return;
        }

        set_status($mac, 'INSTALLING');

        // This URL is now a simple, static path that any web server can handle.
        $seed_url = "http://" . SERVER_IP . "/sessions/" . $mac . "/";
        $kernel_params = "modprobe.blacklist=nvme autoinstall ip=dhcp url=" . ISO_BASE_URL . "/" . ISO_NAME;
        $kernel_params .= " ds=nocloud;seedfrom=" . $seed_url;

        echo "#!ipxe\n";
        echo "echo Starting Ubuntu 24.04 installation for {$mac}...\n";
        echo "kernel " . ISO_BASE_URL . "/casper/vmlinuz {$kernel_params}\n";
        echo "initrd " . ISO_BASE_URL . "/casper/initrd\n";
        echo "boot || goto error\n";
        echo ":error\n";
        echo "echo Critical boot error. Please check server logs. Rebooting in 10s.\n";
        echo "sleep 10\n";
        echo "reboot\n";
    }
}

/**
 * Handles the 'callback' from a successfully installed machine.
 */
function handle_callback(): void {
    $mac = strtolower($_GET['mac'] ?? '');
    $status = strtoupper($_GET['status'] ?? '');

    if (!is_valid_mac($mac) || empty($status)) {
        header("HTTP/1.1 400 Bad Request");
        echo "ERROR: MAC and status parameters are required.";
        return;
    }

    if (set_status($mac, $status)) {
        header("Content-Type: text/plain");
        echo "OK: Status for {$mac} updated to {$status}.";
    } else {
        header("HTTP/1.1 500 Internal Server Error");
        echo "ERROR: Failed to update status for {$mac}.";
    }
}


// --- Main Router ---
$action = $_GET['action'] ?? 'boot';

switch ($action) {
    case 'callback':
        handle_callback();
        break;
    case 'boot':
    default:
        handle_boot();
        break;
}
