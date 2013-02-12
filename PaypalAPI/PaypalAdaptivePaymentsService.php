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

use Symfony\Bundle\FrameworkBundle\Routing\Router;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use GenCheckoutBundle\Command\CommandAP;
use GenCheckoutBundle\Command\Item;
use GenCheckoutBundle\AdaptivePaymentsResult;

/**
 * Allow to do checkout through paypal
 *
 * @author Evgeni Nurkov <http://www.gencreations.com>
 */
class PaypalAdaptivePaymentsService {
    //put your code here
    private $requestEnvelope;

    private $username;
    private $password;
    private $signature;

    private $paypalURL;
    
    private $currency = "EUR";
    
    private $currentUrl;
    
    private $container;
    
    private $cancelUrl;
    private $returnUrl;
    
    private $router;
    
    /** @var \Symfony\Bridge\Monolog\Logger */
    private $logger;
    
    /**
     * Receive the request object at initialization
     * @param ContainerInterface container
     */
    public function __construct(ContainerInterface $container, Router $router, $username, $password, $signature, $useSandbox, $currency){
        $this->container = $container;
        $this->currency = $currency;
        
        $this->username = $username;
        $this->password = $password;
        $this->signature = $signature;
        $this->requestEnvelope = new \RequestEnvelope("en_US");
        
        $this->router = $router;
        $this->logger = $container->get("logger");
        
        $this->currentUrl = $container->get("request")->getUri();
        
        $this->cancelUrl = $this->currentUrl . "?status=cancel";
        $this->returnUrl = $this->currentUrl . "?status=continue";
        
        if ($useSandbox) {
            if (!defined('PP_CONFIG_PATH')) {
                define('PP_CONFIG_PATH', __DIR__ . '/config/sandbox');
            }
            $this->paypalURL = "https://www.sandbox.paypal.com/webscr&";
        } else {
            if (!defined('PP_CONFIG_PATH')) {
                define('PP_CONFIG_PATH', __DIR__ . '/config/production');
            }
            $this->paypalURL = "https://www.paypal.com/webscr&";
        }
        set_time_limit(60);
    }
    
    /**
     * Paypal redirect url
     * @param type $paypalUrl 
     */
    public function setPaypalUrl($paypalUrl){
        $this->paypalURL = $paypalUrl;
    }
    
    
    /**
     * @param CommandAP $command
     * @return AdaptivePaymentsResult : result of adaptive payment operations
     */
    public function doPreapprovedAdaptivePayment(CommandAP $command)
    {
        $this->setPaypalUrl($this->paypalURL . 'cmd=_ap-payment&paykey=');
        
        $result = new AdaptivePaymentsResult();
        
        try {
            $response = $this->Pay($command, 'CREATE');
            $result->setPaykey($response->payKey);
            $result->setCommandData($response);
            // Set status to ERROR, if it is not set later, this will be the final result
            $result->setStatus(AdaptivePaymentsResult::STATUS_ERROR);

            if ($response->responseEnvelope->ack == 'Success') {
                if ($response->paymentExecStatus == 'CREATED') {
                    $response = $this->SetPaymentOptions($command, $result->getPaykey());
                    $result->setCommandData($response);
                    if ($response->responseEnvelope->ack == 'Success') {
                        $response = $this->ExecutePayment($result->getPaykey());
                        $result->setCommandData($response);
                        if ($response->responseEnvelope->ack == 'Success' && 
                            $response->paymentExecStatus == 'COMPLETED') {
                            $response = $this->PaymentDetails($result->getPaykey());
                            $result->setCommandData($response);
                            if ($response->responseEnvelope->ack == 'Success' && 
                                $response->status == 'COMPLETED') {
                                $result->setStatus(AdaptivePaymentsResult::STATUS_SUCCESS);
                            }
                        }
                    }
                }
            }
        } catch(\Exception $e) {
            $this->logger->err('Command PAYPAL-AP PAY error : ' . $e->getCode() . " - " . $e->getMessage());
            $result->setStatus(AdaptivePaymentsResult::STATUS_ERROR);
            throw $e;
        }

        return $result;
    }
    
    /**
     * @param CommandAP $command
     * @return AdaptivePaymentsResult : result of adaptive payment operations
     */
    public function doPreApproval(CommandAP $command)
    {
        $this->setPaypalUrl($this->paypalURL . 'cmd=_ap-preapproval&preapprovalkey=');
        $this->returnUrl .= '&preapprovalkey=${preapprovalkey}';
        $this->cancelUrl .= '&preapprovalkey=${preapprovalkey}';
        
        $preapprovalKey = $command->getPreapprovalKey();
        
        $result = new AdaptivePaymentsResult();
        $result->setPreapprovalKey($preapprovalKey);
        
        if (!$preapprovalKey)
        {
            try {
            	$response = $this->PreApproval($command);
            	
            	$result->setCommandData($response);
            	$result->setPreapprovalKey($response->preapprovalKey);
            	
            	if ($response->responseEnvelope->ack == 'Success') {
                    $result->setStatus(AdaptivePaymentsResult::STATUS_IN_PROGRESS);
                    $result->setHttpResponse(new RedirectResponse($this->paypalURL . $response->preapprovalKey));
            	} else {
                    $result->setStatus(AdaptivePaymentsResult::STATUS_ERROR);
            	}
            } catch(\Exception $e) {
                $this->logger->err('Command PAYPAL-AP PreApproval error : ' . $e->getCode() . " - " . $e->getMessage());
            	$result->setStatus(AdaptivePaymentsResult::STATUS_ERROR);
            	throw $e;
            }
        } else {
            try {
            	$response = $this->PreApprovalDetails($command);
            	$result->setCommandData($response);
                // Set status to ERROR, if it is not set later, this will be the final result
                $result->setStatus(AdaptivePaymentsResult::STATUS_ERROR);
            	
            	if ($response->responseEnvelope->ack == 'Success') {
                    if ($response->approved == 'true' && $response->status == 'ACTIVE') {
                        $result->setStatus(AdaptivePaymentsResult::STATUS_SUCCESS);
                    }
                    if ($response->approved == 'false' && $response->status == 'ACTIVE') {
                        $result->setStatus(AdaptivePaymentsResult::STATUS_PENDING);
                        $result->setHttpResponse(
                            new RedirectResponse($this->paypalURL . $result->getPreapprovalKey())
                        );
                    }
                    if ($response->status == 'CANCELED' || $response->status == 'DEACTIVATED') {
                        $result->setStatus(AdaptivePaymentsResult::STATUS_CANCELED);
                    }
            	}
            } catch(\Exception $e) {
                $this->logger->err('Command PAYPAL-AP PreApprovalDetails error : ' . $e->getCode() . " - " . $e->getMessage());
            	$result->setStatus(AdaptivePaymentsResult::STATUS_ERROR);
            	throw $e;
            }
        }
        
        return $result;
    }
    
    /**
     * @param CommandAP $command
     * @return AdaptivePaymentsResult : result of adaptive payment operations
     */
    public function checkPreApproval(CommandAP $command)
    {
        $result = new AdaptivePaymentsResult();
        $result->setPreapprovalKey($command->getPreapprovalKey());
        
        try {
            $response = $this->PreApprovalDetails($command);
            $result->setCommandData($response);
            // Set status to ERROR, if it is not set later, this will be the final result
            $result->setStatus(AdaptivePaymentsResult::STATUS_ERROR);

            if ($response->responseEnvelope->ack == 'Success') {
                if ($response->approved == 'true' && $response->status == 'ACTIVE') {
                    if ( strtotime($response->endingDate) < time() ) {
                        // Expired
                        $result->setStatus(AdaptivePaymentsResult::STATUS_CANCELED);
                    } else {
                        $result->setStatus(AdaptivePaymentsResult::STATUS_SUCCESS);
                    }
                }
                if ($response->approved == 'false' && $response->status == 'ACTIVE') {
                    $result->setStatus(AdaptivePaymentsResult::STATUS_PENDING);
                }
                if ($response->status == 'CANCELED' || $response->status == 'DEACTIVATED') {
                    $result->setStatus(AdaptivePaymentsResult::STATUS_CANCELED);
                }
            }
        } catch(\Exception $e) {
            $this->logger->err('Command PAYPAL-AP PreApprovalDetails error : ' . $e->getCode() . " - " . $e->getMessage());
            $result->setStatus(AdaptivePaymentsResult::STATUS_ERROR);
            throw $e;
        }
        
        return $result;
    }
    
    /**
     * 
     * @param CommandAP $command
     * @param string $action PAY or CREATE
     * @return \PayResponse
     */
    public function Pay(CommandAP $command, $action = 'PAY')
    {
        $payRequest = new \PayRequest();
    	
        $payRequest->requestEnvelope = $this->requestEnvelope;
        $payRequest->actionType = $action;
        $payRequest->cancelUrl = $this->cancelUrl;
        $payRequest->currencyCode = $this->currency;
        $payRequest->receiverList = $this->getReceiverList($command);
        $payRequest->returnUrl = $this->returnUrl;
        $payRequest->senderEmail = $command->getSenderEmail();
        $payRequest->preapprovalKey = $command->getPreapprovalKey();
        $payRequest->feesPayer = 'SENDER';

    	$paypalService = new \AdaptivePaymentsService();
    	
        return $paypalService->Pay($payRequest, $this->getAPICredentials());
    }
    
    /**
     * 
     * @param CommandAP $command
     * @param string $paykey Paykey received by Pay API call
     * @return \SetPaymentOptionsResponse
     */
    public function SetPaymentOptions(CommandAP $command, $paykey)
    {
        $payRequest = new \SetPaymentOptionsRequest();
        $payRequest->requestEnvelope = $this->requestEnvelope;
        $payRequest->payKey = $paykey;

        $receivers = $command->getReceiverList();
        foreach ($receivers as $receiver) {
            $receiverOptions = new \ReceiverOptions();
            $receiverOptions->receiver = new \ReceiverIdentifier();
            $receiverOptions->receiver->email = $receiver->getEmail();
            $receiverOptions->invoiceData = new \InvoiceData();
            foreach ($receiver->getItems() as $item) {
                $receiverOptions->invoiceData->item[] = $this->getItemDetail($item);
            }
            $payRequest->receiverOptions[] = $receiverOptions;
        }
        
        $paypalService = new \AdaptivePaymentsService();
    	
        return $paypalService->SetPaymentOptions($payRequest, $this->getAPICredentials());
    }
    
    /**
     * @param string $paykey Paykey received by Pay API call
     * @return \PaymentDetailsResponse
     */
    public function PaymentDetails($paykey)
    {
        $payRequest = new \PaymentDetailsRequest();
        $payRequest->requestEnvelope = $this->requestEnvelope;
        $payRequest->payKey = $paykey;
        
        $paypalService = new \AdaptivePaymentsService();
    	
        return $paypalService->PaymentDetails($payRequest, $this->getAPICredentials());
    }
    
    /**
     * @param string $paykey Paykey received by Pay API call
     * @return \ExecutePaymentResponse
     */
    public function ExecutePayment($paykey)
    {
        $payRequest = new \ExecutePaymentRequest();
        $payRequest->requestEnvelope = $this->requestEnvelope;
        $payRequest->payKey = $paykey;
        
        $paypalService = new \AdaptivePaymentsService();
    	
        return $paypalService->ExecutePayment($payRequest, $this->getAPICredentials());
    }
    
    /**
     * @return \InvoiceItem
     */
    private function getItemDetail(Item $item){
    	$paypalItem = new \InvoiceItem();
    	
    	$paypalItem->name = $item->getName();
    	$paypalItem->itemPrice = $item->getAmount();
    	$paypalItem->itemCount = $item->getQuantity();
    	
    	return $paypalItem;
    }
    
    /**
     * 
     * @param CommandAP $command
     * @return \PreapprovalResponse
     */
    public function PreApproval(CommandAP $command)
    {
        $preapprovalRequest = new \PreapprovalRequest();
    	
        $preapprovalRequest->requestEnvelope = $this->requestEnvelope;
        $preapprovalRequest->cancelUrl = $this->cancelUrl;
        $preapprovalRequest->returnUrl = $this->returnUrl;
        $preapprovalRequest->currencyCode = $this->currency;
        $preapprovalRequest->startingDate = date('Y-m-d');
        $preapprovalRequest->endingDate = date('Y-m-d', strtotime(date("Y-m-d", time()) . " + 1 day"));
        $preapprovalRequest->senderEmail = $command->getSenderEmail();
        $preapprovalRequest->feesPayer = 'SENDER';

    	$paypalService = new \AdaptivePaymentsService();
    	
        return $paypalService->Preapproval($preapprovalRequest, $this->getAPICredentials());
    }
    
    /**
     * 
     * @param CommandAP $command
     * @return \PreapprovalDetailsResponse
     */
    public function PreApprovalDetails(CommandAP $command)
    {
        $preapprovalRequest = new \PreapprovalDetailsRequest();
    	
        $preapprovalRequest->requestEnvelope = $this->requestEnvelope;
        $preapprovalRequest->preapprovalKey = $command->getPreapprovalKey();

    	$paypalService = new \AdaptivePaymentsService();
    	
        return $paypalService->PreapprovalDetails($preapprovalRequest, $this->getAPICredentials());
    }
    
    /**
     * 
     * @param CommandAP $command
     * @return \CancelPreapprovalResponse
     */
    public function CancelPreApproval(CommandAP $command)
    {
        $preapprovalRequest = new \CancelPreapprovalRequest();
    	
        $preapprovalRequest->requestEnvelope = $this->requestEnvelope;
        $preapprovalRequest->preapprovalKey = $command->getPreapprovalKey();

    	$paypalService = new \AdaptivePaymentsService();
    	
        return $paypalService->CancelPreapproval($preapprovalRequest, $this->getAPICredentials());
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

