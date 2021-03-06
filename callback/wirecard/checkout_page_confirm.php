<?php
/**
 * Shop System Plugins - Terms of Use
 *
 * The plugins offered are provided free of charge by Wirecard Central Eastern
 * Europe GmbH
 * (abbreviated to Wirecard CEE) and are explicitly not part of the Wirecard
 * CEE range of products and services.
 *
 * They have been tested and approved for full functionality in the standard
 * configuration
 * (status on delivery) of the corresponding shop system. They are under
 * General Public License Version 2 (GPLv2) and can be used, developed and
 * passed on to third parties under the same terms.
 *
 * However, Wirecard CEE does not provide any guarantee or accept any liability
 * for any errors occurring when used in an enhanced, customized shop system
 * configuration.
 *
 * Operation in an enhanced, customized configuration is at your own risk and
 * requires a comprehensive test phase by the user of the plugin.
 *
 * Customers use the plugins at their own risk. Wirecard CEE does not guarantee
 * their full functionality neither does Wirecard CEE assume liability for any
 * disadvantages related to the use of the plugins. Additionally, Wirecard CEE
 * does not guarantee the full functionality for customized shop systems or
 * installed plugins of other vendors of plugins within the same shop system.
 *
 * Customers are responsible for testing the plugin's functionality before
 * starting productive operation.
 *
 * By installing the plugin into the shop system the customer agrees to these
 * terms of use. Please do not use the plugin if you do not agree to these
 * terms of use!
 *
 * @author    WirecardCEE
 * @copyright WirecardCEE
 * @license   GPLv2
 */

// set order-status 0 (not validated)
define('MODULE_PAYMENT_WCP_ORDER_STATUS_NOT_VALIDATED', 0);
// set order-status 1 (pending)
define('MODULE_PAYMENT_WCP_ORDER_STATUS_PENDING', 1);
// set order-status 2 (processing)
define('MODULE_PAYMENT_WCP_ORDER_STATUS_SUCCESS', 2);
// set order-status 99 (canceled)
define('MODULE_PAYMENT_WCP_ORDER_STATUS_FAILED', 99);
chdir('../../');

require_once('includes/modules/payment/wcp_top.php');
wcp_preserve_postparams();
require_once('includes/application_top.php');
wcp_preserve_postparams(true);
require_once('includes/modules/payment/wcp.php');

function debug_msg($msg)
{
    $fh = fopen('logfiles/wirecard_checkout_page_notify_debug.txt', 'a');
    fwrite($fh, date('r') . ". " . $msg . "\n");
    fclose($fh);
}

debug_msg('called script from ' . $_SERVER['REMOTE_ADDR']);
$returnMessage = null;
if ($_POST) {
    $orderStatusSuccess = 2;

    $c = strtoupper($_POST['paymentCode']);
    if(defined("MODULE_PAYMENT_{$c}_ORDER_STATUS_ID"))
        $orderStatusSuccess = constant("MODULE_PAYMENT_{$c}_ORDER_STATUS_ID");

    $languageArray = array(
        'language' => htmlentities($_POST['confirmLanguage']),
        'language_id' => htmlentities($_POST['confirmLanguageId'])
    );

    debug_msg("Finished Initialization of the confirm_callback.php script");
    debug_msg("Received this POST: " . print_r($_POST, 1));
    $order_id = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;

    $order = array();
    $q = xtc_db_query('SELECT orders_status FROM ' . TABLE_ORDERS . ' WHERE orders_id = "' . $order_id . '" LIMIT 1;');
    if ($q->num_rows) {
        $order = $q->fetch_array();
    }

    if ($order['orders_status'] != MODULE_PAYMENT_WCP_ORDER_STATUS_FAILED && $order['orders_status'] != $orderStatusSuccess) {

        $q = xtc_db_query("INSERT INTO " . TABLE_PAYMENT_WCP . "(orders_id, response, created_at) VALUES ('" . $order_id . "','" . serialize($_POST) . "', NOW())");
        if (!$q) {
            $returnMessage = 'Transactiontable update failed.';
        }

        debug_msg('Payment Table updated=' . $q);
        if (isset($_POST['responseFingerprintOrder']) && isset($_POST['responseFingerprint'])) {
            $tempArray = [];
            $responseFingerprintOrder = explode(',', $_POST['responseFingerprintOrder']);
            $responseFingerprintSeed = '';

            switch (wcp_core::constant("MODULE_PAYMENT_{$c}_PLUGIN_MODE")) {
                case 'Demo':
                    $preshared_key = 'B8AKTPWBRMNBV455FG6M2DANE99WU2';
                    break;
                case 'Test':
                    $preshared_key = 'CHCSH7UGHVVX2P7EHDHSY4T2S4CGYK4QBE4M5YUUG2ND5BEZWNRZW5EJYVJQ';
                    break;
                case 'Test3D':
                    $preshared_key = 'DP4TMTPQQWFJW34647RM798E9A5X7E8ATP462Z4VGZK53YEJ3JWXS98B9P4F';
                    break;
                case 'Live':
                default:
                    $preshared_key = trim(wcp_core::constant("MODULE_PAYMENT_{$c}_PRESHARED_KEY"));
                    break;
            }

            $stripslashes = (get_magic_quotes_gpc() || get_magic_quotes_runtime());

            //calculating fingerprint;
            foreach ($responseFingerprintOrder as $k) {
                if ($k != 'secret') {
                    $tempArray[(string)$k] = (string) $_POST[$k];
                }
                if ($stipslashes) {
                    $responseFingerprintSeed .= (strtoupper($k) == 'SECRET' ? $preshared_key : stripslashes(
                        $_POST[$k]
                    ));
                } else {
                    $responseFingerprintSeed .= (strtoupper($k) == 'SECRET' ? $preshared_key : $_POST[$k]);
                }

                if (strcmp($k, 'secret') == 0) {
                    $tempArray[$k] = $preshared_key;
                }
            }

            $hash = hash_init('sha512', HASH_HMAC, $preshared_key);

            foreach ($tempArray as $paramName => $paramValue) {
                hash_update($hash, $paramValue);
            }
            $calculated_fingerprint = hash_final($hash);
            if ($calculated_fingerprint == $_POST['responseFingerprint']) {
                debug_msg('Fingerprint is OK');

                switch ($_POST['paymentState']) {
                    case 'SUCCESS':
                        $order_status = $orderStatusSuccess;
                        break;

                    case 'PENDING':
                        $order_status = MODULE_PAYMENT_WCP_ORDER_STATUS_PENDING;
                        break;

                    default:
                        $order_status = MODULE_PAYMENT_WCP_ORDER_STATUS_FAILED;
                }

                debug_msg('Callback Process');
                $q = xtc_db_query(
                    'UPDATE ' . TABLE_ORDERS . ' SET orders_status=\'' . xtc_db_input(
                        $order_status
                    ) . '\' WHERE orders_id=\'' . $order_id . '\';'
                );
                if (!$q) {
                    $returnMessage = 'Orderstatus update failed.';
                }
                debug_msg('Order-Status updated=' . $q);

                if (MODULE_PAYMENT_WCP_ORDER_STATUS_PENDING !== $order_status) {
                    $avsStatusCode = isset($_POST['avsResponseCode']) ? $_POST['avsResponseCode'] : '';
                    $avsStatusMessage = isset($_POST['avsResponseMessage']) ? $_POST['avsResponseMessage'] : '';
                    if ($avsStatusCode != '' && $avsStatusMessage != '') {
                        $avsStatus = 'AVS Result: ' . $avsStatusCode . ' - ' . $avsStatusMessage;
                    } else {
                        $avsStatus = '';
                    }
                    debug_msg($avsStatus);
                    $q = xtc_db_query(
                        'INSERT INTO ' . TABLE_ORDERS_STATUS_HISTORY . '
             (orders_id,  orders_status_id, date_added, customer_notified, comments)
             VALUES
               (' . (int)$order_id . ', ' . (int)$order_status . ', NOW(), "0", "' . xtc_db_input($avsStatus) . '")'
                    );
                    if (!$q) {
                        $returnMessage = 'Statushistory update failed';
                    }
                    debug_msg('Order-Status-History updated=' . $q);
                }
                if ($orderStatusSuccess === $order_status) {

                    //need language code due to order confirmation mail template
                    $result = xtc_db_query(
                        "SELECT code FROM languages where languages_id = " . xtc_db_input(
                            htmlentities($_POST['confirmLanguageId'])
                        )
                    );

                    $resultRow = $result->fetch_row();

                    $_SESSION['language'] = $languageArray['language'];
                    $_SESSION['language_id'] = $languageArray['language_id'];
                    $_SESSION['language_code'] = ($resultRow['0']) ? $resultRow['0'] : null;

                    $mail = create_status_mail_for_order($order_id);
                    if (!$mail) {
                        $returnMessage = 'Can\'t send confirmation mail.';
                    } else {
                        debug_msg('order confirmation has been sent.');
                    }
                }
            } else {
                $returnMessage = 'Fingerprint validation failed.';
                debug_msg('Invalid Responsefingerprint.');
                debug_msg('calc-fingerprint: ' . $calculated_fingerprint);
                debug_msg('response-fingerprint: ' . $_POST['responseFingerprint']);
                $order_status = MODULE_PAYMENT_WCP_ORDER_STATUS_FAILED;
                $q = xtc_db_query(
                    "UPDATE " . TABLE_ORDERS . "
               SET orders_status='" . xtc_db_input($order_status) . "',
                 gm_cancel_date='" . date('Y-m-d H:i:s') . "'
               WHERE orders_id='" . (int)$order_id . "'"
                );
                $_SESSION['wirecard_checkout_page_fingerprintinvalid'] = 'FAILURE';
            }
        } else {
            debug_msg('No fingerprint found.');
            if (isset($_POST['paymentState']) && $_POST['paymentState'] == 'CANCEL') {
                debug_msg('Order is Canceled');
                $order_status = MODULE_PAYMENT_WCP_ORDER_STATUS_FAILED;

                debug_msg('Callback Process');
                $q = xtc_db_query(
                    "UPDATE " . TABLE_ORDERS . "
               SET orders_status='" . xtc_db_input($order_status) . "',
                 gm_cancel_date='" . date('Y-m-d H:i:s') . "'
               WHERE orders_id='" . (int)$order_id . "'"
                );
                if (!$q) {
                    $returnMessage = 'Orderstatus update failed.';
                }
                debug_msg('Order-Status updated=' . $q);
                $q = xtc_db_query(
                    "INSERT INTO " . TABLE_ORDERS_STATUS_HISTORY . "
               (orders_id,  orders_status_id, date_added, customer_notified, comments)
               VALUES
              ('" . (int)$order_id . "', '" . (int)$order_status . "', NOW(), '0', '')"
                );
                if (!$q) {
                    $returnMessage = 'Statushistory update failed';
                }
                debug_msg('Order-Status-History updated=' . $q);

                if (wcp_core::constant('MODULE_PAYMENT_WCP_DELETE_CANCEL') === 'True') {
                    $canceled = false;
                } else {
                    $canceled = true;
                }

                // restock order
                $restocked = wcp_core::xtc_remove_order($order_id, true, $canceled, true, true);
                if ($restocked) {
                    debug_msg('Order Restocked');
                }
            } elseif (isset($_POST['paymentState']) && $_POST['paymentState'] == 'FAILURE') {
                $message = isset($_POST['message']) ? htmlentities($_POST['message']) : '';
                debug_msg('Order Failed: ' . $message);
                $order_status = MODULE_PAYMENT_WCP_ORDER_STATUS_FAILED;
                debug_msg('Callback Process');
                $q = xtc_db_query(
                    "UPDATE " . TABLE_ORDERS . "
               SET orders_status='" . (int)$order_status . "',
                 gm_cancel_date='" . date('Y-m-d H:i:s') . "'
               WHERE orders_id='" . (int)$order_id . "'"
                );
                if (!$q) {
                    $returnMessage = 'Orderstatus update failed.';
                }
                debug_msg('Order-Status updated=' . $q);
                $q = xtc_db_query(
                    "INSERT INTO " . TABLE_ORDERS_STATUS_HISTORY . "
               (orders_id,  orders_status_id, date_added, customer_notified, comments)
               VALUES
               ('" . (int)$order_id . "', '" . (int)$order_status . "', NOW(), '0', '" . xtc_db_input($message) . "')"
                );

                if (!$q) {
                    $returnMessage = 'Statushistory update failed';
                }
                debug_msg('Order-Status-History updated=' . $q);

                if (wcp_core::constant('MODULE_PAYMENT_WCP_DELETE_FAILURE') === 'True') {
                    $canceled = false;
                } else {
                    $canceled = true;
                }

                // restock order
                $restocked = wcp_core::xtc_remove_order($order_id, true, $canceled, true, true);
                if ($restocked) {
                    debug_msg('Order Restocked');
                }
            } elseif (isset($_POST['paymentState']) && $_POST['paymentState'] == 'SUCCESS') {
                $returnMessage = 'Mandatory fields not used.';
            }
        }
    } else {
        $returnMessage = 'Order status workflow manipulated.';
    }
    xtc_db_close();
} else {
    $returnMessage = 'Not a POST request';
}
echo _wirecardCheckoutPageConfirmResponse($returnMessage);
debug_msg("-- script reached eof - executed without errors --\n");

function create_status_mail_for_order($oID)
{
    $coo_send_order_process = MainFactory::create_object('SendOrderProcess');
    $coo_send_order_process->set_('order_id', $oID);

    return $coo_send_order_process->proceed();
}

function _wirecardCheckoutPageConfirmResponse($message = null)
{
    if ($message != null) {
        debug_msg($message);
        $value = 'result="NOK" message="' . $message . '" ';
    } else {
        $value = 'result="OK"';
    }
    return '<QPAY-CONFIRMATION-RESPONSE ' . $value . ' />';
}