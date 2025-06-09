# WP-CLI Sync

A WP-CLI command to sync dev and production WordPress sites.

## Installation

To install the plugin:

```bash
composer require astappiev/wp-sync-cli
```

## Using

Create a file named `wp-cli.yml` WordPress directory and add remote environment there:
```yaml
@production:
  ssh: reiki@school.silkwayreiki.com
  path: /home/reiki/learn/
```

Run `wp pull` from the project root. `production` environment will be used by default, you can specify another environment by passing its name as first argument.

```bash
wp pull [env_name] [--backup_dir] [--plugins_activate] [--plugins_deactivate] [--upload_dir] [--exclude_dirs]
```
