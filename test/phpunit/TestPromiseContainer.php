<?php
namespace Gt\Promise\Test;

use Gt\Promise\PromiseInterface;
use Gt\Promise\PromiseState;

class TestPromiseContainer {
	private PromiseInterface $promise;
	/** @var ?callable */
	private $resolve;
	/** @var ?callable */
	private $reject;
	/** @var ?callable */
	private $complete;

	public function __construct(
		PromiseInterface $promise,
		callable $resolve = null,
		callable $reject = null,
		callable $complete = null
	) {
		$this->promise = $promise;
		$this->resolve = $resolve;
		$this->reject = $reject;
		$this->complete = $complete;
	}

	public function getPromise():PromiseInterface {
		return $this->promise;
	}

	public function resolve(...$args):?PromiseInterface {
		if($this->promise->getState() !== PromiseState::RESOLVED) {
			$promise = call_user_func($this->resolve, ...$args);
			call_user_func($this->complete);
			return $promise;
		}

		return $this->promise;
	}

	public function reject(...$args):?PromiseInterface {
		$promise = call_user_func($this->reject, ...$args);
		call_user_func($this->complete);
		return $promise;
	}
}
