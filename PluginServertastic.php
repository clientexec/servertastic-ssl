<?php

require_once 'modules/admin/models/SSLPlugin.php';

/**
 * ServerTastic Plugin
 *
 * @category plugin
 * @package  ssl
 * @license  ClientExec License
 * @link     http://www.clientexec.com
 */
class PluginServertastic extends SSLPlugin
{
    public $mappedTypeIds = array(
        SSL_CERT_RAPIDSSL => 'RapidSSL',
        SSL_CERT_RAPIDSSL_WILDCARD => 'RapidSSLWildcard',
        SSL_SECTIGO_POSITIVESSL => 'PositiveSSL',
        SSL_SECTIGO_POSITIVESSL_WILDCARD => 'PositiveSSLWildcard',
        SSL_CERT_GEOTRUST_QUICKSSL_PREMIUM => 'QuickSSLPremium',
        SSL_CERT_GEOTRUST_TRUE_BUSINESSID => 'TrueBizID',
        SSL_CERT_GEOTRUST_TRUE_BUSINESSID_EV => 'TrueBizIDEV',
        SSL_CERT_GEOTRUST_TRUE_BUSINESSID_WILDCARD => 'TrueBizIDWildcard',
        SSL_CERT_VERISIGN_SECURE_SITE => 'SecureSite',
        SSL_CERT_VERISIGN_SECURE_SITE_EV => 'SecureSiteEV',
        SSL_CERT_VERISIGN_SECURE_SITE_PRO => 'SecureSitePro',
        SSL_CERT_VERISIGN_SECURE_SITE_PRO_EV => 'SecureSiteProEV',
        SSL_CERT_THAWTE_SSL_WEBSERVER => 'SSLWebServer',
        SSL_CERT_THAWTE_SSL_WEBSERVER_WILDCARD => 'SSLWebServerWildCard',
        SSL_CERT_THAWTE_SSL_WEBSERVER_EV => 'SSLWebServerEV',
        SSL_CERT_THAWTE_SSL123 => 'SSL123'
    );

    /**
     * Indicates if the plugin uses an invite URL process.
     * @var bool
     */
    public $usingInviteURL = false;

    /**
     * Gets the variables needed for the plugin.
     *
     *
     * @return array An array of configured variables.
     */
    function getVariables()
    {
        $variables = array(
            lang('Plugin Name') => array(
                'type'          => 'hidden',
                'description'   => lang('How CE sees this plugin (not to be confused with the Signup Name)'),
                'value'         => lang('ServerTastic')
            ),
            lang('API Key') => array(
                'type'          => 'text',
                'description'   => lang('Enter your API Key here.'),
                'value'         => ''
            ),
            lang('Actions') => array(
                'type'          => 'hidden',
                'description'   => lang('Current actions that are active for this plugin.'),
                'value'         => 'Purchase,CancelOrder (Cancel Order),ResendApprovalEmail (Resend Approval Email),RenewCertificate (Renew Certificate)',
            )
        );

        return $variables;
    }

    /**
     * Initiates the purchase.
     *
     * @param array $params The package parameters.
     *
     * @return string A message indicating that the order was placed.
     *
     * @throws CE_Exception if the order token is not generated.
     */
    function doPurchase($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $params = $this->buildParams($userPackage);
        $orderPlaced = false;

        // We need a CSR and admin email to complete the order
        // These are required in the UI, but you can do purchase plugin action without them
        if ($params['CSR'] == '' || $params['adminEmail'] == '') {
            throw new CE_Exception('Missing CSR or Admin E-Mail');
        }

        $certId = $userPackage->getCustomField('Certificate Id');

        // No certificate stored, purchase the certificate
        if ($certId == '') {
            // Generate an Order Token
            $orderToken = $this->generateToken($params);

            // We need an order token to continue
            if ($orderToken == '') {
                throw new CE_Exception('Failed to generate order token, cannot complete purchase');
            }

            $userPackage->setCustomField('Certificate Id', $orderToken);
            $params['certId'] = $orderToken;

            $orderPlaced = $this->placeOrder($params);
        } else {
            $status = $this->doGetCertStatus($params);

            if ($status == 'Order Placed') {
                $orderPlaced = $this->placeOrder($params);
            }
        }

        if ($orderPlaced) {
            return 'Purchase completed. Certificate sent for approval.';
        }
    }

    /**
     * Generates the order token which is required
     * for further action on an order.
     *
     * @param array $params The package parameters.
     *
     * @return string The order token element if the response is successful.
     *
     * @throws CE_Exception if no response from the API.
     */
    private function generateToken($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $params = $this->buildParams($userPackage);

        $years = "-12";
        if ($params['numYears'] == '1') {
            $years = "-12";
        }
        if ($params['numYears'] == '2') {
            $years = "-24";
        }
        if ($params['numYears'] == '3') {
            $years = "-36";
        }

        $arguments = array(
            'api_key'                       => $params['API Key'],
            'st_product_code'               => $this->getProductNameById($params['typeId']) . $years,
            'end_customer_email'            => $params['EmailAddress'],
            'reseller_unique_reference'     => md5(time())
        );

        $response = $this->makeRequest('/order/generatetoken', $arguments);

        if (!is_object($response)) {
            throw new CE_Exception(
                'ServerTastic Plugin Error: Failed to communicate with ServerTastic',
                EXCEPTION_CODE_CONNECTION_ISSUE
            );
        }

        if (isset($response->success)) {
            return $response->order_token;
        }
    }

    /**
     * Places the order.
     *
     * @param array $params The package parameters.
     * @param bool $isRenewal Flag to indicate if the order is a renewal. By default this is false.
     *
     * @return bool True if the success element is set from the response, otherwise false.
     *
     * @throws CE_Exception if no response from the API.
     */
    private function placeOrder($params, $isRenewal = false)
    {
        $arguments = $this->configureCertificate($params, $isRenewal);

        // Must be POST
        $response = $this->makeRequest('/order/place', $arguments, 'POST');

        if (!is_object($response)) {
            throw new CE_Exception(
                'ServerTastic Plugin Error: Failed to communicate with ServerTastic',
                EXCEPTION_CODE_CONNECTION_ISSUE
            );
        }

        return isset($response->success);
    }

    /**
     * Configures the parameters required for making the certificate order.
     *
     * @param array $params The package parameters.
     * @param bool $isRenewal A flag to indicate if the order is a renewal.
     *
     * @return array An array of arguments to be sent in the query string.
     */
    private function configureCertificate($params, $isRenewal)
    {
        $arguments = array(
            'order_token'               => $params['certId'],
            'csr'                       => str_replace("\r\n", '', $params['CSR']),
            'admin_contact_title'       => 'N/A',
            'admin_contact_first_name'  => $params['FirstName'],
            'admin_contact_last_name'   => $params['LastName'],
            'admin_contact_email'       => $params['EmailAddress'],
            'admin_contact_phone'       => $this->validatePhone($params['Phone']),
            'tech_contact_title'        => $params['Tech Job Title'],
            'tech_contact_first_name'   => $params['Tech First Name'],
            'tech_contact_last_name'    => $params['Tech Last Name'],
            'tech_contact_email'        => $params['Tech E-Mail'],
            'tech_contact_phone'        => $this->validatePhone($params['Tech Phone']),
            'approver_email_address'    => $this->getApproverEmailAddress($params),
            'renewal'                   => $isRenewal ? 1 : 0
        );

        $productName = $this->getProductNameById($params['typeId']);

        // Server count for Symantec products
        // TODO: Add a field for this in the UI and only show for Symantec(Verisign)
        if (strpos($productName, 'SecureSite') !== false) {
            $arguments['server_count'] = 1;
        }

        // Web server type for EV
        // TODO: Show this in the UI for EV products
        if (strpos($productName, 'EV') !== false) {
            $arguments['WebServerType'] = $params['serverType'];
        }

        return $arguments;
    }

    /**
     * Validates the phone number.
     *
     * @param string $phoneNumber The phone number.
     *
     * @return string The phone number with non-numerical characters removed.
     */
    private function validatePhone($phoneNumber)
    {
        // Strip out non numerical values
        return preg_replace('/[^\d]/', '', $phoneNumber);
    }

    /**
     * Gets the approver email address by comparing what's in the UI to
     * the API's approved email list.
     *
     * @param array $params The package parameters.
     *
     * @return string The approver email.
     *
     * @throws CE_Exception if the approver email from the package does not match the API approver email list.
     */
    private function getApproverEmailAddress($params)
    {
        $approverEmail = '';

        // Decode the CSR so we can get the domain name
        $csrDetails = $this->decodeCSR($params['CSR']);

        if ($csrDetails) {
            $domainName = $csrDetails['commonName'];
        }

        $approverEmails = $this->getApproverList($domainName);

        $emailFound = false;
        foreach ($approverEmails as $email) {
            if ($email == $params["adminEmail"]) {
                $emailFound = true;
                $approverEmail = $params["adminEmail"];
                break;
            }
        }

        if (!$emailFound) {
            $exceptionMessage = "Admin Email must be one of these values: \n\n";

            foreach ($approverEmails as $email) {
                $exceptionMessage .= $email . "\n";
            }

            throw new CE_Exception($exceptionMessage);
        }

        return $approverEmail;
    }

    /**
     * Decodes the CSR.
     *
     * @param mixed $csr The encoded certificate signing request.
     *
     * @return array The information contained in the CSR in long format.
     *
     * @throws CE_Exception if the server does not have PHP openssl extension installed/loaded.
     */
    private function decodeCSR($csr)
    {
        // TODO: Update to Servertastic API endpoint for decoding CSR
        // See https://tools.servertastic.com/certificate-decoder, according to ServerTastic, this will page will have an API endpoint we can use
        if (extension_loaded('openssl')) {
            return openssl_csr_get_subject($csr, false);
        } else {
            throw new CE_Exception("Failed to purchase certificate, openssl extension for PHP required.");
        }
    }

    /**
     * Gets the list of emails required for approval.
     *
     * @param string $domainName The domain name.
     *
     * @return array The list of approved emails from the API.
     */
    private function getApproverList($domainName)
    {
        $emails = array();

        $arguments = array(
            'domain_name'   => $domainName
        );

        $response = $this->makeRequest('/order/approverlist', $arguments);

        if (!is_object($response)) {
            throw new CE_Exception(
                'ServerTastic Plugin Error: Failed to communicate with ServerTastic',
                EXCEPTION_CODE_CONNECTION_ISSUE
            );
        }

        if ($response->success) {
            foreach ($response->approver_email as $approverEmail) {
                if (isset($approverEmail->email)) {
                    $emails[] = $approverEmail->email;
                }
            }
        };

        return $emails;
    }

    /**
     * Gets the CSR information from an existing order.
     *
     * @param array $params The package parameters.
     *
     * @return array The CSR information.
     *
     * @throws CE_Exception if no response from the API.
     */
    function doParseCSR($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $params = $this->buildParams($userPackage);

        $arguments = array(
            'order_token'       => $params['certId']
        );

        $response = $this->makeRequest('/order/review', $arguments);

        if (!is_object($response)) {
            throw new CE_Exception(
                'ServerTastic Plugin Error: Failed to communicate with ServerTastic',
                EXCEPTION_CODE_CONNECTION_ISSUE
            );
        }

        $information = array();

        if (isset($response->success)) {
            $information['non_csr'] = false;
            $information['domain'] = $response->domain_name;
            $information['city'] = $response->organisation_info->address->city;

            $information['state'] =
                $response->organisation_info->address->region;

            $information['country'] =
                $response->organisation_info->address->country;

            $information['organization'] = $response->organisation_info->name;
            $information['ou'] = $response->organisation_info->division;
            $information['email'] = $response->end_customer_email;
        }

        return $information;
    }

    /**
     * Gets the status of a certificate order.
     *
     * @param array $params The package parameters.
     *
     * @return string The status of the order.
     *
     * @throws CE_Exception if no response from the API.
     */
    function doGetCertStatus($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $params = $this->buildParams($userPackage);

        // certId is the order token, if it doesn't exist, don't call the API
        if ($params['certId'] == '') {
            return '';
        }

        $arguments = array(
            'order_token'   => $params['certId']
        );

        $response = $this->makeRequest('/order/review', $arguments);

        if (!is_object($response)) {
            throw new CE_Exception(
                'ServerTastic Plugin Error: Failed to communicate with ServerTastic',
                EXCEPTION_CODE_CONNECTION_ISSUE
            );
        }

        if (isset($response->success)) {
            $status = strval($response->order_status);

            $this->setExpirationDate($response->certificate_list, $userPackage);

            $domainName = strval($response->domain_name);
            $userPackage->setCustomField('Certificate Domain', $domainName);

            if ($status == 'Completed') {
                // cert is issued, so mark our internal status
                // as issued so we don't poll anymore.
                $userPackage->setCustomField(
                    'Certificate Status',
                    SSL_CERT_ISSUED_STATUS
                );
            }

            return $status;
        }
    }

    /**
     * Sets the expiration date from the list of certificates
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function setExpirationDate(array $certificates, $package) {
        if (empty($certificates)) {
            return;
        }

        // Get the first certificate in the list
        $certificate = $certificates[0];

        if ($certificate->expiration_date === null) {
            return;
        }

        $expiration = new DateTime(strval($certificate->expiration_date));
        $expirationDate = $expiration->format('m/d/Y h:i:s A');

        $package->setCustomField(
            'Certificate Expiration Date',
            $expirationDate
        );
    }

    /**
     * Re-sends the approval email to the configured admin email.
     *
     * @param $params An array of package parameters.
     *
     * @return string A message indicating the email was re-sent if the request was successful.
     *
     * @throws CE_Exception if no response from the API.
     */
    function doResendApprovalEmail($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $params = $this->buildParams($userPackage);

        $arguments = array(
            'order_token'     => $params['certId'],
            'email_type'      => 'Approver'
        );

        $response = $this->makeRequest('/order/resendemail', $arguments);

        if (!is_object($response)) {
            throw new CE_Exception(
                'ServerTastic Plugin Error: Failed to communicate with ServerTastic',
                EXCEPTION_CODE_CONNECTION_ISSUE
            );
        }

        if (isset($response->success)) {
            return 'Successfully re-sent approval e-mail.';
        }
    }

    /**
     * Cancels an existing order.
     *
     * @param $params array The package parameters.
     *
     * @return string A message indicating the cancellation was completed if request was successful.
     *
     * @throws CE_Exception if no response from the API.
     */
    function doCancelOrder($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $params = $this->buildParams($userPackage);

        $arguments = array(
            'order_token'     => $params['certId']
        );
        $response = $this->makeRequest('/order/cancel', $arguments);

        if (!is_object($response)) {
            throw new CE_Exception(
                'ServerTastic Plugin Error: Failed to communicate with ServerTastic',
                EXCEPTION_CODE_CONNECTION_ISSUE
            );
        }

        if (isset($response->success)) {
            $userPackage->setCustomField("Certificate Id", '');
            return 'Successfully cancelled certificate order.';
        }
    }

    /**
     * Renews a certificate order.
     *
     * @param array $params The package parameters.
     *
     * @return string A message indicating the purchase was successful.
     */
    function doRenewCertificate($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $params = $this->buildParams($userPackage);
        $isRenewal = true;
        $orderPlaced = false;

        // Reset the certificate status
        $userPackage->setCustomField("Certificate Status", '');

        $orderToken = $this->generateToken($params);

        if ($orderToken == '') {
            throw new CE_Exception(
                "Failed to generate order token, cannot complete renewal"
            );
        }

        // Set our new order token as the cert id
        $userPackage->setCustomField('Certificate Id', $orderToken);
        $params['certId'] = $orderToken;

        $orderPlaced = $this->placeOrder($params, $isRenewal);

        if ($orderPlaced) {
            return 'Renewal successful. Certificate sent for approval.';
        }
    }

    function doReissueCertificate($params) {
        // TODO: Implement for multi year certificates
    }

    /**
     * Makes a request to the API.
     *
     * @param string $url The API endpoint (e.g. /order/place).
     * @param array $arguments The arguments to append to the query string.
     * @param string $requestType The type of API request (GET, POST, etc.).
     *
     * @return SimpleXmlElement The API response.
     *
     * @throws CE_Exception if the response errors from NE_Network class or API.
     */
    private function makeRequest($url, $arguments, $requestType = '')
    {
        require_once 'library/CE/NE_Network.php';

        $request = 'https://api.servertastic.com/v2';
        $request .= $url . '.json'; // XML response is deprecated

        $i = 0;
        foreach ($arguments as $name => $value) {
            $value = urlencode($value);
            if (!$i) $request .= "?$name=$value";
            else $request .= "&$name=$value";
            $i++;
        }

        CE_Lib::log(4, 'ServerTastic Params: ' . print_r($arguments, true));

        if ($requestType == 'POST') {
            $response = NE_Network::curlRequest($this->settings, $request, false, false, false, $requestType);
        } else {
            $response = NE_Network::curlRequest($this->settings, $request, false, false, true);
        }

        if (is_a($response, 'NE_Error')) {
            throw new CE_Exception($response);
        }

        if (!$response) {
            return false; // don't want process an empty array
        }

        $response = json_decode($response);

        if (isset($response->error)) {
            throw new CE_Exception(
                'ServerTastic Plugin Error: ' . $response->error->message
            );
        }

        return $response;
    }

    /**
     * Gets the available plugin actions based on the status of the order.
     * This will change the dropdown in the admin UI.
     *
     * @param UserPackage $userPackage The user package.
     *
     * @return array An array of plugin actions.
     */
    function getAvailableActions($userPackage)
    {
        $actions = array();
        $params['userPackageId'] = $userPackage->id;
        try {
            $status = $this->doGetCertStatus($params);

            if ($status == ''
                || $status == 'Order Placed'
                || $status == 'Cancelled'
                || $status == 'Roll Back') {
                    $actions[] = 'Purchase';
            } else if ($status == 'Awaiting Customer Verification'
                || $status == 'Awaiting Provider Approval') {
                    $actions[] = 'CancelOrder (Cancel Order)';
                    $actions[] = 'ResendApprovalEmail (Resend Approval Email)';
            } else if ($status == 'Completed') {
                    $actions[] = 'CancelOrder (Cancel Order)';
                    $actions[] = 'RenewCertificate (Renew Certificate)';

                    // TODO: Only show if billing cycle is greater than 1 year
                    // This is currently not active
                    $actions[] = 'ReissueCertificate (Reissue Certificate)';
            }
        } catch (CE_Exception $e) {
            $actions[] = 'Purchase';
        }

        return $actions;
    }

    /**
     * Gets the certificate types offered by ServerTastic.
     *
     * @return array The array of certificate types.
     */
    function getCertificateTypes()
    {
        return array(
            'RapidSSL' => 'RapidSSL',
            'RapidSSLWildcard' => 'RapidSSL Wildcard',
            'PositiveSSL' => 'PositiveSSL',
            'PositiveSSLWildcard' => 'PositiveSSLWildcard',
            'QuickSSLPremium' => 'GeoTrust QuickSSL Premium',
            'TrueBizID' => 'GeoTrust True BusinessID',
            'TrueBizIDWildcard' => 'GeoTrust TrueBusinessID Wildcard',
            'TrueBizIDEV' => 'GeoTrust TrueBusinessID with EV',
            'SecureSite' => 'Symantec Secure Site',
            'SecureSiteEV' => 'Symantec Secure Site EV',
            'SecureSitePro' => 'Symantec Secure Site Pro',
            'SecureSiteProEV' => 'Symantec Secure Site Pro EV',
            'SSLWebServer' => 'Thawte SSL Web Server',
            'SSLWebServerWildCard' => 'Thawte SSL Web Server Wildcard',
            'SSLWebServerEV' => 'Thawte SSL Web Server EV',
            'SSL123' => 'Thawte SSL 123'
        );
    }

    /**
     * Gets the product name by its associated id.
     *
     * @param var $id The id as a defined constant.
     *
     * @return string The product name.
     */
    private function getProductNameById($id)
    {
        switch ($id) {
            case SSL_CERT_RAPIDSSL:
                return 'RapidSSL';
            case SSL_CERT_RAPIDSSL_WILDCARD:
                return 'RapidSSLWildcard';
            case SSL_SECTIGO_POSITIVESSL:
                return 'PositiveSSL';
            case SSL_SECTIGO_POSITIVESSL_WILDCARD:
                return 'PositiveSSLWildcard';
            case SSL_CERT_GEOTRUST_QUICKSSL_PREMIUM:
                return 'QuickSSLPremium';
            case SSL_CERT_GEOTRUST_TRUE_BUSINESSID:
                return 'TrueBizID';
            case SSL_CERT_GEOTRUST_TRUE_BUSINESSID_WILDCARD:
                return 'TrueBizIDWildcard';
            case SSL_CERT_GEOTRUST_TRUE_BUSINESSID_EV:
                return 'TrueBizIDEV';
            case SSL_CERT_VERISIGN_SECURE_SITE:
                return 'SecureSite';
            case SSL_CERT_VERISIGN_SECURE_SITE_PRO:
                return 'SecureSitePro';
            case SSL_CERT_VERISIGN_SECURE_SITE_EV:
                return 'SecureSiteEV';
            case SSL_CERT_VERISIGN_SECURE_SITE_PRO_EV:
                return 'SecureSiteProEV';
            case SSL_CERT_THAWTE_SSL_WEBSERVER:
                return 'SSLWebServer';
            case SSL_CERT_THAWTE_SSL_WEBSERVER_WILDCARD:
                return 'SSLWebServerWildCard';
            case SSL_CERT_THAWTE_SSL_WEBSERVER_EV:
                return 'SSLWebServerEV';
            case SSL_CERT_THAWTE_SSL123:
                return 'SSL123';
        }
    }

    /**
     * Gets the web server types.
     *
     * @param mixed $type The type id.
     *
     * @return array An array of web server types.
     */
    function getWebserverTypes($type)
    {
        return array();
    }
}
