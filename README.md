# Hirale GAMeasurementProtocol

A module for integrating with the [Google Analytics 4 Measurement Protocol API](https://developers.google.com/analytics/devguides/collection/protocol/ga4/reference?client_type=gtag#overview), sending events from server side.

This module can work with Mage_GoogleAnalytics module. 
For duplicate key events, you can consult this page [https://support.google.com/analytics/answer/12313109?hl=en](https://support.google.com/analytics/answer/12313109?hl=en)

# Attention: this module reuses added and removed items of module Mage_GoogleAnalytics, it will apply a patch for core module. Check patch here [https://github.com/hirale/magento-lts/pull/1/files](https://github.com/hirale/magento-lts/pull/1/files)


## Install

### Install with [Magento Composer Installer](https://github.com/Cotya/magento-composer-installer)

```bash
composer require hirale/openmage-ga4-measurement:dev-master
```

## Usage

### Setup

1. Generate an API SECRET in the Google Analytics UI. To create a new secret, navigate to `Admin > Data Streams > choose your stream > Measurement Protocol > Create`.
2. Get measurement ID associated with a stream, found in the Google Analytics UI under `Admin > Data Streams > choose your stream > Measurement ID`.
3. Go to openmage system config `System > Configuration > Sales > Google API > Measurement Protocol`. Insert the parameters from step 1 and 2, save.


## License

The Open Software License v. 3.0 (OSL-3.0). Please see [License File](LICENSE.md) for more information.
