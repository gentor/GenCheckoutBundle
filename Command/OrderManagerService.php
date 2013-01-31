<?php

namespace GenCheckoutBundle\Command;


interface OrderManagerService {
	
	/**
	 * @param unknown $id
	 * @return Command
	 */
	public function getCommand($id);
	
	public function cancelCommand(Command $command);
	
	public function validateCommand(Command $command);
	
	public function errorCommand(Command $command, $error = null);
}
