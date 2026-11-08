.. include:: ../../../Includes.rst.txt

==================
Main Configuration
==================

Each activated payment method in the GiroCockpit has its own credentials.
These must be adopted accordingly for the payment methods that are to be offered in the shop.
The different possibilities for configuring the credentials are described in the :ref:`Credential <credentials>` section.

.. code-block:: typoscript

   plugin.tx_cartgirosolution {
       redirectTypeNum = 2278106
   }

|

.. container:: table-row

   Property
      plugin.tx_cartgirosolution.redirectTypeNum
   Data type
      int
   Description
      The redirectTypeNum is used to select the correct plugin when a user is redirected from the payment page back to the store.
   Default
      2278106

GiroCheckout server
===================

By default the GiroCheckout SDK sends payment requests to the GiroCheckout 2 platform (`gc2-prod`,
https://payment.girocheckout.de). Another server can be set per installation, e.g. in
:file:`config/system/additional.php`.

.. code-block:: php

   $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['cart_girosolution']['server'] = 'gc2-preprod';

|

.. container:: table-row

   Property
      $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['cart_girosolution']['server']
   Data type
      string
   Description
      Server alias of the GiroCheckout SDK (`gc2-prod`, `gc2-preprod`, `gc1-prod`, `gc1-dev`) or the base URL of the
      GiroCheckout API. If empty, the default of the SDK is used, which can also be set with the environment variable
      `GIROCHECKOUT_SERVER`.
   Default
      empty
