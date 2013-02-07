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
namespace GenCheckoutBundle;

/**
 * Response returned by a checkout service on docheckout method call
 *
 * @author Evgeni Nurkov <http://www.gencreations.com>
 */
class AdaptivePaymentsResult extends CheckoutResult
{
    private $paykey;
    
    private $preapprovalKey;
    
    public function getPaykey(){
        return $this->paykey;
    }
	
    public function setPaykey($paykey){
        $this->paykey = $paykey;
    }

    public function getPreapprovalKey(){
        return $this->preapprovalKey;
    }
	
    public function setPreapprovalKey($preapprovalKey){
        $this->preapprovalKey = $preapprovalKey;
    }
}
