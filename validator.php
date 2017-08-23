<?php

error_reporting ( E_ALL );
ini_set ('display_errors', 1);

/*
 * Download and validate metadata from JAGGER [1].
 *
 * Metadata URL has to be passed via HTTP GET variable called `filename'
 * defined in Federation Validators settings (JAGGER). Then, metadata is saved
 * as a XML file `ENCODED_ENTITYID'.xml (ENCODED_ENTITYID is generated
 * automatically by JAGGER) in `tmp/' subdirectory which must exists and be
 * writable by the user running web server. After validation process is
 * finished, the temporary XML file with metadata is deleted.
 *
 * As a XML validator, XSD-Validator [2] by Adrian Mouat [3,4] is deployed.
 *
 * SAML-Validators (XML schemas, PHP, etc.) is written by Jan Oppolzer [5] from
 * CESNET [6] and can be obtained from GitHub repository [7].
 *
 * [1] http://jagger.heanet.ie/
 * [2] https://github.com/amouat/xsd-validator/
 * [3] https://github.com/amouat/
 * [4] http://www.adrianmouat.com/
 * [5] jan.oppolzer@cesnet.cz
 * [6] https://www.cesnet.cz/
 * [7] https://github.com/JanOppolzer/saml-validators/
 *
 */

/* variable definitions
 */
$KEY_SIZE               = 2048; # bits
$CERTIFICATE_VALIDITY   = 30;   # days
$XSD_VALIDATOR          = "./xsd-validator/xsdv.sh";

/* validators
 */
$VALIDATORS = array (
    "endpoints-entityid"    => array (
        "enabled"           => 1,
        "schema"            => "endpoints-entityid.xsd",
        "info"              => array (
            0               => "Endpoints and entityID use HTTPS.",
            2               => "Endpoints and entityID must use HTTPS.",
        ),
    ),
    "organization"          => array (
        "enabled"           => 0,
        "schema"            => "organization.xsd",
        "info"              => array (
            0               => "Organization defined.",
            2               => "Organization undefined.",
        ),
    ),
    "republish-target"      => array (
        "enabled"           => 1,
        "schema"            => "republish-target.xsd",
        "info"              => array (
            0               => "Republish Target configured correctly or not at all.",
            2               => "Republish Target misconfigured.",
        ),
    ),
    "certificate"           => array (
        "enabled"           => 0,
        "schema"            => "certificate.xsd",
        "info"              => array (
            0               => "Certificate defined.",
            2               => "Certificate undefined.",
        ),
    ),
);

/* writeXML function to produce XML output
 *
 * FIXME: rewrite to just a dumb function writeXML (return code, info message, debug message)
 *
 */
function writeXML ($returncode, $validations, $debug) {
    $xml = new XMLWriter();
    $xml->openURI('php://output');
    $xml->startDocument('1.0', 'utf-8');
    $xml->setIndent(true);

    $xml->startElement('validation');

        $xml->writeElement('returncode', $returncode);

        foreach ($validations as $result => $validator) {
            if ($debug === 1 && !empty ($GLOBALS["VALIDATORS"][$result]["info"][$validator["returncode"]])) {
                $xml->writeElement ('info', $GLOBALS["VALIDATORS"][$result]["info"][$validator["returncode"]]);
            }
        }

        foreach ($validations as $validation) {
            if (!empty ($validation["message"]))
                $xml->writeElement ('message', $validation["message"]);
        }

    $xml->endElement();

    $xml->endDocument();
    $xml->flush();
}

/* writeXMLError function to produce various error messages as a XML document
 *
 * FIXME: see writeXML() FIXME
 */
function writeXMLError ($returncode, $message) {
    $xml = new XMLWriter ();
    $xml->openURI ("php://output");
    $xml->startDocument ("1.0", "utf-8");
    $xml->setIndent ("true");
    $xml->startElement ("validation");
    $xml->writeElement ("returncode", $returncode);
    $xml->writeElement ("message", $message);
    $xml->endElement ();
    $xml->endDocument ();
    $xml->flush ();
}

/* isIDP function returns true in case $metadata is IdP
 */
function isIDP ($metadata) {
    $sxe = new SimpleXMLElement (file_get_contents ($metadata));
    $sxe->registerXPathNamespace ('md','urn:oasis:names:tc:SAML:2.0:metadata');
    $result = $sxe->xpath ('//md:IDPSSODescriptor');

    if (count ($result) > 0) {
        return true;
    }
}

/* validation function (XML schema)
 */
function validateMetadata ($metadata, $xmlschema) {
    if (file_exists ($GLOBALS["XSD_VALIDATOR"])) {
        $command = "$GLOBALS[XSD_VALIDATOR] xsd/$xmlschema $metadata";
        exec ($command, $output);

        $message = null;
        foreach ($output as $line) {
            $message .= $line;
        }

        if (preg_match ("/validates/", $message)) {
            $returncode = 0;
            $message = "";
        } else {
            $returncode = 2;

            switch ($xmlschema) {
                case "endpoints-entityid.xsd":
                    $message = "Endpoints/entityID must start with https://.";
                    break;
                case "organization.xsd":
                    $message = "No organization.";
                    break;
                case "republish-target.xsd":
                    $message = "Wrong republish-target.";
                    break;
                case "certificate.xsd":
                    $message = "Missing certificate.";
                    break;
            }
        }

        return array ($returncode, $message);
    } else {
        writeXML ("2", "XSD Validator missing", 1);
        exit;
    }
}

/* validation function (certificate's public key size and validity)
 */
function certificateCheck ($metadata) {
    $sxe = new SimpleXMLElement (file_get_contents($metadata));
    $sxe->registerXPathNamespace ('ds','http://www.w3.org/2000/09/xmldsig#');
    $result = $sxe->xpath ('//ds:X509Certificate');

    if (count ($result) > 0) {

        foreach ($result as $cert) {
            $X509Certificate = "-----BEGIN CERTIFICATE-----\n" . trim ($cert) . "\n-----END CERTIFICATE-----";
            $cert_info = openssl_x509_parse ($X509Certificate, true);
            $cert_validTo = date ("Y-m-d", $cert_info['validTo_time_t']);
            $cert_validFor = floor ((strtotime ($cert_validTo)-time ())/(60*60*24));
            $pub_key = openssl_pkey_get_details (openssl_pkey_get_public ($X509Certificate));

            if (($pub_key['bits'] >= $GLOBALS['KEY_SIZE']) && ($cert_validFor >= $GLOBALS['CERTIFICATE_VALIDITY'])) {
                $returncode = 0;
                #$message = "Public key size is at least " . $GLOBALS['KEY_SIZE'] . ". That is OK.";
                $message = "";
            } elseif (($pub_key['bits'] < $GLOBALS['KEY_SIZE']) && ($cert_validFor >= $GLOBALS['CERTIFICATE_VALIDITY'])) {
                $returncode = 2;
                $message = "Public key size has to be greater than or equal to " . $GLOBALS['KEY_SIZE'] . ". Yours is " . $pub_key[bits] . ".";
            } elseif (($pub_key['bits'] >= $GLOBALS['KEY_SIZE']) && ($cert_validFor < $GLOBALS['CERTIFICATE_VALIDITY'])) {
                $returncode = 2;
                $message = "Certificate should be valid at least for " . $GLOBALS['CERTIFICATE_VALIDITY'] . " days. Yours is valid only for " . $cert_validFor . ".";
            } else {
                $returncode = 2;
                $message = "Certificate should be valid at least for " . $GLOBALS['CERTIFICATE_VALIDITY'] . " days. Yours is valid only for " . $cert_validFor . ". And public key size has to be greater than or equal to " . $GLOBALS['KEY_SIZE'] . " bits. Yours is " . $pub_key[bits] . ".";
            }
        }

        return array($returncode, $message);

    } else {
        $returncode = 2;
        $message    = "No certificate found.";
        return array ($returncode, $message);
    }
}

/* validation function: //shibmd:Scope[@regexp=false]
 */
function scopeRegexpCheck ($metadata) {
    $sxe = new SimpleXMLElement (file_get_contents($metadata));
    $sxe->registerXPathNamespace ('shibmd','urn:mace:shibboleth:metadata:1.0');
    # FIXME: check for <shibmd:Scope> only with IDPs
    $result = $sxe->xpath ('//shibmd:Scope[@regexp]');
    $resultCount = count ($result);

    $regexpValue = array ();
    for ($i=0; $i<$resultCount; $i++) {
        $regexpValue[$i] = (string) $result[$i]['regexp'];
    }

    $regexpResult = -1;
    foreach ($regexpValue as $regexp) {
        if (strcmp ($regexp, 'false') === 0) {
            $returncode = 0;
        } else {
            $returncode = 2;
        }

        $regexpResult = max ($regexpResult, $returncode);
    }

    $regexpMessage = array (
        -1 => 'Something went wrong with scope regexp check.',
         #0 => 'Scope regexp is false. That is OK.',
         0 => '',
         2 => 'Scope regexp must be "false"!',
    );

    return array ($regexpResult, $regexpMessage[$regexpResult]);
}

/* validation function: //shibmd:Scope === //EntityDescriptor[@entityID] substring
 */
function scopeValueCheck ($metadata) {
    $sxe = new SimpleXMLElement (file_get_contents($metadata));
    $sxe->registerXPathNamespace ('md','urn:oasis:names:tc:SAML:2.0:metadata');
    $entityID = $sxe->xpath ('/md:EntityDescriptor[@entityID]');
    $entityID = ((string) $entityID[0]['entityID']);
    # FIXME: `_` & `-` within DNS names?
    $pattern = '/https:\/\/([a-z0-9.]*)\/.*/i';
    $replacement = '$1';
    $hostname = preg_replace ($pattern, $replacement, $entityID);

    $sxe->registerXPathNamespace ('shibmd','urn:mace:shibboleth:metadata:1.0');
    $result = $sxe->xpath ('//shibmd:Scope[@regexp]');
    $resultCount = count ($result);

    $scopeValue = array ();
    for ($i=0; $i<$resultCount; $i++) {
        $scopeValue[$i] = (string) $result[$i][0];
    }

    $scopeResult = -1;
    foreach ($scopeValue as $scope) {
        if (preg_match ("/$scope/", $hostname)) {
            $regResult = 0;
        } else {
            $regResult = 2;
        }
        $scopeResult = max ($scopeResult, $regResult);
    }

    $scopeMessage = array (
        -1 => 'Something went wrong with scope value check.',
         #0 => 'Scope value is a substring of the entityID. That is OK.',
         0 => '',
         2 => 'Scope value must be a substring of the entityID!',
    );

    return array ($scopeResult, $scopeMessage[$scopeResult]);
}

/* validation function: //mdui:UIInfo
 */
function uiinfoCheck ($metadata) {
    $sxe = new SimpleXMLElement (file_get_contents($metadata));
    $sxe->registerXPathNamespace ('md','urn:oasis:names:tc:SAML:2.0:metadata');
    $sxe->registerXPathNamespace ('mdui','urn:oasis:names:tc:SAML:metadata:ui');

    $UIInfoDisplayNameCS        = $sxe->xpath ('//mdui:UIInfo/mdui:DisplayName[@xml:lang="cs"]');
    $UIInfoDisplayNameEN        = $sxe->xpath ('//mdui:UIInfo/mdui:DisplayName[@xml:lang="en"]');
    $UIInfoDescriptionCS        = $sxe->xpath ('//mdui:UIInfo/mdui:Description[@xml:lang="cs"]');
    $UIInfoDescriptionEN        = $sxe->xpath ('//mdui:UIInfo/mdui:Description[@xml:lang="en"]');
    $UIInfoInformationURLCS     = $sxe->xpath ('//mdui:UIInfo/mdui:InformationURL[@xml:lang="cs"]');
    $UIInfoInformationURLEN     = $sxe->xpath ('//mdui:UIInfo/mdui:InformationURL[@xml:lang="en"]');
    # FIXME: URL to Logo must be HTTPS!
    $UIInfoLogo                 = $sxe->xpath ('//mdui:UIInfo/mdui:Logo');

    $messages = array();
    if (empty ($UIInfoDisplayNameCS))
        array_push ($messages, "DisplayName/cs missing.");
    if (empty ($UIInfoDisplayNameEN))
        array_push ($messages, "DisplayName/en missing.");
    if (empty ($UIInfoDescriptionCS))
        array_push ($messages, "Description/cs missing.");
    if (empty ($UIInfoDescriptionEN))
        array_push ($messages, "Description/en missing.");
    if (empty ($UIInfoInformationURLCS))
        array_push ($messages, "InformationURL/cs missing.");
    if (empty ($UIInfoInformationURLEN))
        array_push ($messages, "InformationURL/en missing.");
    if (isIDP ($metadata)) {
        if (empty ($UIInfoLogo))
            array_push ($messages, "Logo missing.");
    }

    $message = "";
    if (count ($messages) > 0) {
        $returncode = 2;
        for ($i=0; $i<=count ($messages); $i++) {
            $message .= array_pop ($messages) . " ";
        }
    } else {
        $returncode = 0;
    }

    return array ($returncode, $message);
}

/* validation function: //md:Organization
 */
function organizationCheck ($metadata) {
    $sxe = new SimpleXMLElement (file_get_contents($metadata));
    $sxe->registerXPathNamespace ('md','urn:oasis:names:tc:SAML:2.0:metadata');
    $result = $sxe->xpath ('/md:EntityDescriptor/md:Organization');

    if (count ($result) > 0) {
        $returncode = 0;
        $message    = "";
    } else {
        $returncode = 2;
        $message    = "Organization undefined.";
    }

    return array ($returncode, $message);
}

/* validation function: //md:ContactPerson[@contactType=technical]
 */
function contactPersonTechnicalCheck ($metadata) {
    $sxe = new SimpleXMLElement (file_get_contents($metadata));
    $sxe->registerXPathNamespace ('md','urn:oasis:names:tc:SAML:2.0:metadata');
    $result = $sxe->xpath ('/md:EntityDescriptor/md:ContactPerson[@contactType="technical"]');

    if (count ($result) > 0) {
        $returncode = 0;
        #$message = "Technical contact defined.";
        $message = "";
    } else {
        $returncode = 2;
        $message = "Technical contact undefined.";
    }

    return array ($returncode, $message);
}

/* error messages definitions
 */
$error = array (
    "URL_none"      => array (
        "code"      => 2,
        "info"      => "No metadata URL supplied in HTTP GET variable `filename'.",
    ),
    "URL_invalid"   => array (
        "code"      => 2,
        "info"      => "Invalid metadata URL supplied in HTTP GET variable `filename'.",
    ),
    "URL_empty"     => array (
        "code"      => 2,
        "info"      => "An URL supplied in HTTP GET variable `filename' contains no metadata.",
    ),
    "XSDV_missing"  => array (
        "code"      => 2,
        "info"      => "XSD Validator missing.",
    ),
);

/* output texts definitions
 */
$info = array(
    "certificate-check" => array(
        0 => "Certificate key size and validity correct.",
        2 => "Certificate key size or validity incorrect! For more info, see https://www.eduid.cz/cs/tech/metadata-profile",
    ),
);

/* debug: show <info> elements even for success validations
 *  value 0 (default) means no debug
 *  value 1 means debug
 *  other values produces $debug=1
 *
 *  FIXME: clear input fileds
 */
$debug = !empty ($_GET["debug"]) ? 1 : 0;

/* filename: metadata URL
 */
$filename = !empty ($_GET["filename"]) ? $_GET["filename"] : 0;

if (!$filename) {
    writeXMLError (2, "No metadata URL defined using HTTP GET variable `filename'.");
    exit;
}
else {
    if (!filter_var($filename, FILTER_VALIDATE_URL)) {
        writeXML ($error['URL_invalid']['code'], $error['URL_invalid']['info'], $debug);
        exit;
    }
}

/* fetch metadata
 */
$URLsplit = explode ("/", $filename);
$encoded_entityid = $URLsplit[count($URLsplit)-2];
$metadata = "tmp/" . $encoded_entityid . uniqid('-') . ".xml";

!$md_content = @file_get_contents ("$filename");

if (empty ($md_content)) {
    writeXMLError (2, "Metadata file has no content.");
    exit;
} elseif (!$md_content) {
    writeXMLError (2, "No metadata URL");
    exit;
} else {
    file_put_contents ("$metadata", $md_content);
}

/* run enabled validators (XML schema)
 */
$validations = array ();
foreach ($VALIDATORS as $validator => $value) {
    if ($VALIDATORS[$validator]["enabled"] == 1) {
        list ($returncode, $message) = validateMetadata ($metadata, $VALIDATORS[$validator]["schema"]);

        $result = array (
            "returncode"    => $returncode,
            "message"       => $message,
        );

        $validations[$validator] = $result;
    }
}

/* run enabled validators (PHP scripts)
 */
// certificate validity
list ($returncode, $message) = certificateCheck ($metadata);
$result = array (
    "returncode" => $returncode,
    "message"    => $message,
);
$validations ["certificateCheck"] = $result;

// shibmd:Scope tests
if (isIDP ($metadata)) {
// shibmd:Scope[@regexp=false]
    list ($returncode, $message) = scopeRegexpCheck ($metadata);
    $result = array (
        "returncode" => $returncode,
        "message"    => $message,
    );
    $validations ["scopeRegexpCheck"] = $result;

// shibmd:Scope === substr(entityID)
    list ($returncode, $message) = scopeValueCheck ($metadata);
    $result = array (
        "returncode" => $returncode,
        "message"    => $message,
    );
    $validations ["scopeValueCheck"] = $result;
}

// uiinfo
list ($returncode, $message) = uiinfoCheck ($metadata);
$result = array (
    "returncode" => $returncode,
    "message"    => $message,
);
$validations ["uiinfoCheck"] = $result;

// organization
list ($returncode, $message) = organizationCheck ($metadata);
$result = array (
    "returncode" => $returncode,
    "message"    => $message,
);
$validations ["organizationCheck"] = $result;

// technical contact
list ($returncode, $message) = contactPersonTechnicalCheck ($metadata);
$result = array (
    "returncode" => $returncode,
    "message"    => $message,
);
$validations ["contactPersonTechnicalCheck"] = $result;

/* validation result
 */
$returncode_final = -1;
foreach ($validations as $validation) {
    $returncode_final = max ($returncode_final, $validation["returncode"]);
}

writeXML ($returncode_final, $validations, $debug);

/* delete temporary XML file with metadata
 */
exec ("rm -f $metadata");

?>

