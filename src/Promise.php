<?php
namespace Gt\Promise;

use Gt\Promise\Chain\Chainable;
use Gt\Promise\Chain\FinallyChain;
use Gt\Promise\Chain\ThenChain;
use Throwable;
use Http\Promise\Promise as HttpPromiseInterface;

class Promise implements PromiseInterface, HttpPromiseInterface {
	private string $state;
	/** @var mixed */
	private $resolvedValue;
	/** @var Chainable[] */
	private array $chain;
	/** @var callable */
	private $executor;
	private ?Throwable $rejection;

	public function __construct(callable $executor) {
		$this->state = HttpPromiseInterface::PENDING;
		$this->chain = [];
		$this->rejection = null;
		
		$this->executor = $executor;
		$this->call();
	}

	public function then(
		callable $onFulfilled = null,
		callable $onRejected = null
	):PromiseInterface {
		if($onFulfilled || $onRejected) {
			array_push($this->chain, new ThenChain(
				$onFulfilled,
				$onRejected
			));
			// TODO: If onfulfilled is null, should we create a new Catch()?
		}

		return $this;
	}

	public function catch(
		callable $onRejected
	):PromiseInterface {
		return $this->then(null, $onRejected);
	}

	public function finally(
		callable $onFulfilledOrRejected
	):PromiseInterface {
		if($onFulfilledOrRejected instanceof Throwable) {
			array_push($this->chain, new FinallyChain(
				null,
				$onFulfilledOrRejected
			));
		}
		else {
			array_push($this->chain, new FinallyChain(
				$onFulfilledOrRejected,
				null
			));
		}

		return $this;
	}

	public function complete(
		callable $onFulfilled = null,
		callable $onRejected = null
	):void {
		$this->then($onFulfilled, $onRejected);
		$this->sortChain();
		$this->handleChain();
	}

	private function sortChain():void {
		usort($this->chain, fn($a, $b)
			=> $a instanceof FinallyChain ? 1 : 0);
	}

	private function handleChain():void {
		$rejectedForwardQueue = [];
		if(!is_null($this->rejection)) {
			array_push(
				$rejectedForwardQueue,
				$this->rejection
			);
		}

		while($then = array_shift($this->chain)) {
			try {
				if($reason = array_shift($rejectedForwardQueue)) {
					$rejectedResult = $then->callOnRejected($reason);
					if($rejectedResult instanceof Throwable) {
						array_push(
							$rejectedForwardQueue,
							$rejectedResult
						);
					}
					elseif(!is_null($rejectedResult)) {
						$this->rejection = null;
						$this->resolvedValue = $rejectedResult;
					}
				}
				else {
					$value = $then->callOnFulfilled($this->resolvedValue);
					if(!is_null($value)) {
						$this->resolvedValue = $value;
					}
				}
			}
			catch(Throwable $reason) {
				array_push($rejectedForwardQueue, $reason);
			}
		}

		if($reason = array_shift($rejectedForwardQueue)) {
			throw $reason;
		}
	}

	public function getState():string {
		return $this->state;
	}

	/** @param bool $unwrap */
	public function wait($unwrap = true) {
		// TODO: Implement wait() method.
	}

	private function call():void {
		call_user_func(
			$this->executor,
			/** @param mixed $value */
			function($value = null) {
				$this->resolve($value);
			},
			function(Throwable $reason) {
				$this->reject($reason);
			}
		);
	}

	/** @param mixed $value */
	private function resolve($value):void {
		if($value instanceof PromiseInterface) {
			$this->rejection = new PromiseResolvedWithAnotherPromiseException();
			return;
		}

		$this->resolvedValue = $value;
	}

	private function reject(Throwable $reason):void {
		$this->rejection = $reason;
	}
}