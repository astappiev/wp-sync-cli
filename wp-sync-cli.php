<?php
/**
 * Plugin Name: WP-CLI Sync
 * Plugin URI: https://github.com/astappiev/wp-sync-cli
 * Description: A WP-CLI command to sync dev and production WordPress sites.
 * Version: 0.0.1
 * Author: Oleh Astappiev
 * Author URI: https://github.com/astappiev
 * License: MIT
 */

if (!defined('WP_CLI')) {
    return;
}

class WP_CLI_Remote_Base extends WP_CLI_Command
{
    const DEFAULT_ALIAS = '@production';

    protected string $current_date;
    protected array $alias;
    protected string $local_url;
    protected string $remote_url;
    protected int $errors_count = 0;

    public function __construct()
    {
        parent::__construct();

        $this->current_date = date('Ymd\THis');
        $this->local_url = WP_HOME;
    }

    private function provided_alias_or_default($aliases, $provided, $default): string
    {
        if (empty($aliases)) {
            WP_CLI::error('No aliases defined. Please add aliases to your wp-cli.yml file.');
            return '';
        }

        if (!empty($provided)) {
            if ($provided[0] !== '@') {
                $provided = '@' . $provided;
            }

            if (isset($aliases[$provided])) {
                return $provided;
            } else {
                WP_CLI::error("Alias $provided not found.");
                return '';
            }
        }

        if (isset($aliases[$default])) {
            return $default;
        }

        WP_CLI::error('Please provide an alias as first argument: wp <command> <alias>');
        return '';
    }

    protected function parse_alias($args_alias): void
    {
        $aliases = WP_CLI::get_runner()->aliases;
        $alias = $this->provided_alias_or_default($aliases, $args_alias, $this::DEFAULT_ALIAS);
        $alias_data = $aliases[$alias];

        if (!isset($alias_data['ssh'])) {
            WP_CLI::error("Alias $alias does not have `ssh` configuration.");
        }

        if (!isset($alias_data['path'])) {
            WP_CLI::error("Alias $alias does not have `path` configuration.");
        }

        $alias_data['name'] = $alias;
        $alias_data['append_args'] = ['--ssh=' . $alias_data['ssh'] . ':' . $alias_data['path']];
        $this->alias = $alias_data;

        $this->check_connection();
    }

    protected function check_connection(): void
    {
        $local_url = $this->run_local('option get home');
        if ($this->local_url != $local_url) {
            WP_CLI::error("Local home URL does not match WP_HOME");
        }

        $this->remote_url = $this->run_remote('option get home');
        if ($this->local_url == $this->remote_url) {
            WP_CLI::error("Remote home URL does matches alias URL");
        }
    }

    protected function run_local($command, $fatal = true): string
    {
        $cmd = WP_CLI::runcommand($command . ' --quiet', [
            'launch' => false,
            'exit_error' => false,
            'return' => 'all',
        ]);

        if ($cmd->return_code != 0) {
            $error_message = "Error running command `$command`: $cmd->stderr";
            if ($fatal) {
                throw new RuntimeException($error_message);
            } else {
                WP_CLI::error($error_message, false);
                $this->errors_count++;
            }
        }

        return $cmd->stdout;
    }

    protected function run_remote($command, $fatal = true): string
    {
        if (empty($this->alias)) {
            if ($fatal) {
                throw new RuntimeException('No alias provided');
            } else {
                WP_CLI::error("Can't run command `$command`: no alias provided", false);
                return '';
            }
        }

        $cmd = WP_CLI::runcommand($command . ' --quiet', [
            'exit_error' => false,
            'return' => 'all',
            'command_args' => $this->alias['append_args'],
        ]);

        if ($cmd->return_code != 0) {
            $error_message = "Error running remote command `$command` on `{$this->alias['name']}`: $cmd->stderr";
            if ($fatal) {
                throw new RuntimeException($error_message);
            } else {
                WP_CLI::error($error_message, false);
                $this->errors_count++;
            }
        }

        return $cmd->stdout;
    }

    protected function exec($command, $fatal = true): string
    {
        $cmd = WP_CLI::launch($command, exit_on_error: false, return_detailed: true);

        if ($cmd->return_code != 0) {
            $error_message = "Error executing `$command`: $cmd->stderr";
            if ($fatal) {
                throw new RuntimeException($error_message);
            } else {
                WP_CLI::error($error_message, false);
                $this->errors_count++;
            }
        }

        return $cmd->stdout;
    }
}

class WP_CLI_Sync extends WP_CLI_Remote_Base
{
    private array $options;

    public function register()
    {
        if (!defined('WP_CLI') || !WP_CLI) {
            return;
        }

        WP_CLI::add_command('pull', [$this, 'pull']);
    }

    public function pull($args, $assoc_args): void
    {
        $this->parse_alias($args[0] ?? null);

        $this->options = array_merge($assoc_args, [
            'backup_dir' => 'backup',
            'plugins_activate' => '',
            'plugins_deactivate' => '',
            'upload_dir' => 'wp-content/uploads',
            'exclude_dirs' => '',
        ]);

        // Ensure backup directory exists
        if (!is_dir($this->options['backup_dir'])) {
            WP_CLI::log("Creating backup directory: {$this->options['backup_dir']}");
            mkdir($this->options['backup_dir'], 0755, true);
        }

        // Check if the uploads directory exists
        if (!is_dir($this->options['upload_dir'])) {
            if (is_dir("web/app/uploads")) {
                $this->options['upload_dir'] = "web/app/uploads"; // Fallback for Bedrock structure
            } else {
                WP_CLI::error("Uploads directory does not exist. Please provide a valid upload directory.");
                return;
            }
        }

        WP_CLI::line(WP_CLI::colorize("%BPulling from {$this->alias['name']}%n"));

        try {
            WP_CLI::run_command(['maintenance-mode', 'activate'], ['force' => true]);
            $target_path = $this->options['backup_dir'] . '/backup_' . $this->current_date . '.sql';
            WP_CLI::log("Backing up database");
            $this->run_local("db export $target_path --single-transaction");

            $path = $this->options['backup_dir'] . '/pull_' . $this->current_date . '.sql';
            WP_CLI::log("Pulling database from {$this->alias['name']}");
            $db_dump = $this->run_remote("db export - --single-transaction");
            file_put_contents($path, $db_dump);
            WP_CLI::log("Database pulled from remote");

            WP_CLI::log("Resetting local database");
            $this->run_local("db reset --yes");

            WP_CLI::log("Importing database to local site");
            $this->run_local("db import $path");

            WP_CLI::log("Replacing site URL");
            $this->run_local("search-replace '$this->remote_url' '$this->local_url' --all-tables");

            $this->plugins_management();
            $this->sync_uploads();
        } catch (Exception $e) {
            WP_CLI::error($e->getMessage());
        } finally {
            WP_CLI::run_command(['maintenance-mode', 'deactivate']);
        }

        if ($this->errors_count > 0) {
            WP_CLI::warning(WP_CLI::colorize("%BFinished with " . $this->errors_count . " errors%n"));
        } else {
            WP_CLI::success(WP_CLI::colorize("%GAll Tasks Finished%n"));
        }
    }

    private function plugins_management(): void
    {
        if (!empty($this->options['plugins_activate'])) {
            WP_CLI::log("Activating plugins");

            $plugins_list = preg_replace('/[ ,]+/', ' ', trim($this->options['plugins_activate']));
            $this->run_local("plugin activate " . $plugins_list);
        }

        if (!empty($this->options['plugins_deactivate'])) {
            WP_CLI::log("Deactivating plugins");

            $plugins_list = preg_replace('/[ ,]+/', ' ', trim($this->options['plugins_deactivate']));
            $this->run_local("plugin deactivate " . $plugins_list);
        }
    }

    private function sync_uploads(): void
    {
        $has_rsync = WP_CLI::launch('rsync --version', exit_on_error: false, return_detailed: true);
        if ($has_rsync->return_code != 0) {
            WP_CLI::warning("rsync not found. Please install rsync.");
            $this->errors_count++;
            return;
        }

        $excludes = '';
        if ($exclude_dirs = $this->options['exclude_dirs']) {
            $exclude_dirs = explode(',', $exclude_dirs);
            foreach ($exclude_dirs as $dir) {
                $excludes .= ' --exclude=' . $dir;
            }
        }

        $uploads_folder = $this->options['upload_dir'];
        WP_CLI::log("Syncing uploads folder");
        $command = 'rsync -avhP ' . $this->alias['ssh'] . ':' . $this->alias['path'] . '/' . $uploads_folder . '/ ./' . $uploads_folder . '/' . $excludes;
        WP_CLI::debug($command);

        $rsync = WP_CLI::launch($command, false, true);
        if ($rsync->return_code != 0) {
            WP_CLI::warning("rsync not found. Please install rsync.");
            $this->errors_count++;
            return;
        }

        WP_CLI::log("Uploads folder synced");
    }
}

(new WP_CLI_Sync())->register();
