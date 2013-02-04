<?php
/**
 * Copyright 2013 Evgeni Nurkov <http://www.gencreations.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace GenCheckoutBundle\PaypalAPI;

use Monolog\Logger;

//TODO : ADD IT IN PATH SYMFONY !
$path = __DIR__ . "/merchant-sdk-php/lib";
set_include_path(get_include_path() . PATH_SEPARATOR . $path);
include("services/AdaptivePayments/AdaptivePaymentsService.php");

use GenCheckoutBundle\Command\CommandAP;
use Symfony\Bundle\FrameworkBundle\Routing\Router;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GenCheckoutBundle\AdaptivePaymentsResult;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Allow to do checkout through paypal
 *
 * @author Evgeni Nurkov <http://www.gencreations.com>
 */
class PaypalAdaptivePaymentsService {
    //put your code here
    private $apiConfigPath;
    
    private $username;
    private $password;
    private $signature;

    private $paypalURL;
    
    private $currency = "EUR";
    
    private $currentUrl;
    
    private $container;
    
    private $cancelUrl;
    private $returnUrl;
    
    private $session;
    
    private $router;
    
    /** @var \Symfony\Bridge\Monolog\Logger */
    private $logger;
    
    /**
     * Receive the request object at initialization
     * @param ContainerInterface container
     */
    public function __construct(ContainerInterface $container, Router $router, $username, $password, $signature, $useSandbox){
        $this->container = $container;
        
        $this->username = $username;
        $this->password = $password;
        $this->signature = $signature;
        
        $this->router = $router;
        $this->logger = $container->get("logger");
        
        $this->session = $container->get("session");
        
        $this->currentUrl = $container->get("request")->getUri();
        
        $this->cancelUrl = $this->currentUrl . "?status=cancel";
        $this->returnUrl = $this->currentUrl . "?status=continue";
        
        if($useSandbox){
            $this->apiConfigPath = __DIR__ . '/config/ap-payment/sandbox';
            $this->paypalURL = "https://www.sandbox.paypal.com/webscr&cmd=_ap-payment&paykey=";
        }else{
            $this->apiConfigPath = __DIR__ . '/config/ap-payment/production';
            $this->paypalURL = "https://www.paypal.com/webscr&cmd=_ap-payment&paykey=";
        }
    }
    
    /**
     * Set PP_CONFIG_PATH for PayPal API
     */
    public function setConfigPath(){
        if (!defined('PP_CONFIG_PATH')) {
            define('PP_CONFIG_PATH', $this->apiConfigPath);
        }
    }
    
    /**
     * Paypal redirect url
     * @param type $paypalUrl 
     */
    public function setPaypalUrl($paypalUrl){
        $this->paypalURL = $paypalUrl;
    }
    
    
    /**
     * @param Command $command
     * @return AdaptivePaymentsResult : result of adaptive payment operations
     */
    public function doAdaptivePayment(CommandAP $command)
    {
        $this->setConfigPath();
    	
        $paykey = $this->session->get("checkout.payment.paykey");
        
        $result = new AdaptivePaymentsResult();
        $result->setPaykey($paykey);
        
        $paypalService = new \AdaptivePaymentsService();
        
        if (!$paykey)
        {
            //$response = $this->setExpressCheckoutRequest($command);
            try {
            	$response = $this->Pay($command);
            	
            	$result->setCommandData($response);
            	$result->setPaykey($response->payKey);
            	
            	if ($response->responseEnvelope->ack == 'Success') {
                    $this->session->set("checkout.payment.paykey", $response->payKey);

                    $result->setStatus(AdaptivePaymentsResult::STATUS_IN_PROGRESS);

                    $result->setHttpResponse(new RedirectResponse($this->paypalURL . $response->payKey));
            	} else {
                    $result->setStatus(AdaptivePaymentsResult::STATUS_ERROR); //TODO Check this !
                    echo $response->error[0]->errorId;
            	}
            	
            } catch(\Exception $e) {
                $this->logger->err('Command PAYPAL-AP PAY error : ' . $e->getCode() . " - " . $e->getMessage());
            	$this->session->remove("checkout.payment.paykey");
            	$result->setStatus(AdaptivePaymentsResult::STATUS_ERROR);
            	throw $e;
            }
        return $result;    
            
        } else {
            	$this->session->remove("checkout.payment.paykey");
exit;            
            if($this->container->get("request")->query->get("status") == "continue"){
                
                $paykey = $this->session->get("checkout.payment.paykey");
                
                $ecDetails = null;
                $ecPayment = null;
                
                try{
                	$ecDetails = $this->getExpressCheckoutDetails($paykey);
                	
                	if($ecDetails->Ack == 'Success'){
                		
                		$ecPayment = $this->doExpressCheckout($command, $ecDetails->GetExpressCheckoutDetailsResponseDetails);
                		
                		if($ecPayment->Ack == 'Success'){
                			$paymentInfo = $ecPayment->DoExpressCheckoutPaymentResponseDetails->PaymentInfo;
                			if($paymentInfo && count($paymentInfo) > 0){
                				$paymentStatus = $paymentInfo[0]->PaymentStatus;
                				 
                				if($paymentStatus == 'Completed' || $paymentStatus == 'Completed-Funds-Held'){
                					$result->setStatus(CheckoutResult::STATUS_SUCCESS);
                				}else if($paymentStatus == 'In-Progress' || $paymentStatus == 'Partially-Refunded' || $paymentStatus == 'Pending' || $paymentStatus == 'Processed'){
                					$result->setStatus(CheckoutResult::STATUS_PENDING);
                					$result->setCommandData($paymentInfo[0]->PendingReason);
                				}else{
                					$result->setStatus(CheckoutResult::STATUS_ERROR);
                				}
                			}else{ //simple success : need to check after if ok
                				$result->setStatus(CheckoutResult::STATUS_PENDING);
                			}
                			
                		}else{
                			$this->logger->err('Command PAYPAL doExpressCheckoutPayment [' . $paykey . '] ack not success : ' . $ecPayment->Ack , array(json_encode($ecPayment)));
                			$result->setStatus(CheckoutResult::STATUS_ERROR);
                		}
                	}else{
                		$this->logger->err('Command PAYPAL getExpressCheckoutDetails [' . $paykey . '] ack not success : ' . $ecDetails->Ack , array(json_encode($ecDetails)));
                		$result->setStatus(CheckoutResult::STATUS_ERROR); 
                	}
                }catch(\Exception $e){
                	$this->session->remove("checkout.payment.paykey");
                	$this->logger->err('Command PAYPAL [' . $paykey . '] get/do express checkout payment error : ' . $e->getCode() . " - " . $e->getMessage(), array("ecDetails" => json_encode($ecDetails), "ecPayment" => json_encode($ecPayment)));
                	$result->setCommandData($e->getCode() . " - " . $e->getMessage());
                	$result->setStatus(CheckoutResult::STATUS_ERROR);
                }
                
                $this->session->remove("checkout.payment.paykey");
            }else{
                //Session delete checkout.payment.paykey
                $this->session->remove("checkout.payment.paykey");
                $result->setStatus(CheckoutResult::STATUS_CANCELED);
                return $result;
            }
        }
        
        return $result;
    }
    
    /**
     * 
     * @param Command $command
     * @param string $action PAY or CREATE
     * @return \PayResponse
     */
    public function Pay(CommandAP $command, $action = 'PAY')
    {
        $payRequest = new \PayRequest();
    	
        $payRequest->requestEnvelope = new \RequestEnvelope("en_US");
        $payRequest->actionType = $action;
        $payRequest->cancelUrl = $this->cancelUrl;
        $payRequest->currencyCode = $this->currency;
        $payRequest->receiverList = $this->getReceiverList($command);
        $payRequest->returnUrl = $this->returnUrl;
        $payRequest->senderEmail = $command->getSenderEmail();
        $payRequest->preapprovalKey = $command->getPreapprovalKey();

    	$paypalService = new \AdaptivePaymentsService();
    	
        return $paypalService->Pay($payRequest, $this->getAPICredentials());
    }
    
    /**
     * @param CommandAP $command
     * @return \ReceiverList
     */
    private function getReceiverList(CommandAP $command) {
        $list = $command->getReceiverList();
        $receiver = array();
        
        for ($i = 0; $i < count($list); $i++) {
            $receiver[$i] = new \Receiver();
            $receiver[$i]->email = $list[$i]->getEmail();
            $receiver[$i]->amount = $list[$i]->getAmount();
            $receiver[$i]->primary = $list[$i]->isPrimary();
            $receiver[$i]->invoiceId = $list[$i]->getInvoiceId();
            $receiver[$i]->paymentType = $list[$i]->getPaymentType();
        }
        
        return new \ReceiverList($receiver);
    }

    /**
     * @return \APICredentialsType
     */
    private function getAPICredentials(){
    	$apiCredentials = new \PPSignatureCredential($this->username, $this->password, $this->signature);
    	
    	return $apiCredentials;
    }
    
    public function setCurrency($currency){
        $this->currency = $currency;
    }
    
}

