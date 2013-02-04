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
namespace GenCheckoutBundle\Command;
/**
 * Represent a command and have to be passed to the Adaptive Payments service
 * to do a payment.
 *
 * @author Evgeni Nurkov <http://www.gencreations.com>
 */
interface CommandAP {
    //put your code here
    public function getSenderEmail();
	
    public function getReceiverList();

    public function getPreapprovalKey();
    
//    public function getCustommer();
//    
//    public function getItems();
//    
//    public function getTotalAmount();
//    
//    public function getShippingAmount();
//    
//    public function getItemsAmount();
//    
//    public function getShippingDiscount();
}


