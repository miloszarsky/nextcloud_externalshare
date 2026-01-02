# External Share for Nextcloud

Upload files to external services (like transfer.sh, 0x0.st) directly from Nextcloud's sharing panel and get shareable links.

## Features

- Upload files to external upload services from the sharing sidebar
- Get shareable links instantly
- Copy link to clipboard with one click
- Send link via email directly from Nextcloud
- Configurable upload service URL, HTTP method, and authentication
- Support for custom headers (e.g., Max-Days, Max-Downloads)

## Requirements

- Nextcloud 28 - 45
- PHP 8.0+

## Installation

### From GitHub

1. Download or clone this repository:
   ```bash
   cd /var/www/nextcloud/apps
   git clone https://github.com/miloszarsky/nextcloud_externalshare.git externalshare
   ```

2. Set proper permissions:
   ```bash
   chown -R www-data:www-data externalshare
   ```

3. Enable the app:
   ```bash
   sudo -u www-data php /var/www/nextcloud/occ app:enable externalshare
   ```

### Manual Installation

1. Download the latest release
2. Extract to `/var/www/nextcloud/apps/externalshare`
3. Set permissions: `chown -R www-data:www-data externalshare`
4. Enable via Admin → Apps or using `occ app:enable externalshare`

## Configuration

1. Go to **Administration Settings** → **Sharing**
2. Find the **External Share** section
3. Configure:
   - **Upload Service URL**: The endpoint URL (e.g., `https://transfer.sh`)
   - **HTTP Method**: PUT (for transfer.sh) or POST (for 0x0.st)
   - **Authentication Token** (optional): Bearer token for authenticated services
   - **Custom Headers** (optional): One per line, format `Header-Name: value`

### Example Configurations

**transfer.sh:**
- URL: `https://transfer.sh`
- Method: PUT
- Custom Headers:
  ```
  Max-Days: 7
  Max-Downloads: 10
  ```

**0x0.st:**
- URL: `https://0x0.st`
- Method: POST

## Usage

1. Open the **Files** app
2. Click on a file to open the details sidebar
3. Find the **External Share** section
4. Click **Upload to External Service**
5. Copy the generated link or send it via email

## License

AGPL-3.0 - See [LICENSE](LICENSE) for details.

## Author

Milos Zarsky - [rootik.cz](https://rootik.cz)
