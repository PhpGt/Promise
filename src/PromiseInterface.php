<?php
namespace Gt\Promise;

interface PromiseInterface {
	/**
	 * The then() method returns a Promise. It takes up to two arguments:
	 * callback functions for the success and failure cases of the Promise.
	 *
	 * If supplied, $onFulfilled will be invoked once the promise is
	 * fulfilled. The callback will be passed the resulting value as its
	 * only argument.
	 *
	 * If supplied, $onRejected will be invoked once the promise is
	 * rejected. The callback will be passed the Throwable reason as the
	 * only argument.
	 */
	public function then(
		callable $onFulfilled = null,
		callable $onRejected = null
	):PromiseInterface;

	/**
	 * The catch() method returns a Promise and deals with rejected cases
	 * only. It behaves the same as calling $promise->then(null, onRejected)
	 * which means that you have to provide an onRejected function even if
	 * you want to fall back to a null result value.
	 *
	 * The only parameter of the $onRejected callback is a Throwable. A
	 * specific type of Throwable can be used to catch only specific errors.
	 */
	public function catch(
		callable $onRejected
	):PromiseInterface;

	/**
	 * The finally() method returns a Promise. When the promise is settled,
	 * i.e either fulfilled or rejected, the specified callback function is
	 * executed. This provides a way for code to be run whether the promise
	 * was fulfilled successfully or rejected once the Promise has been
	 * dealt with.
	 *
	 * This helps to avoid duplicating code in both the promise's
	 * then() and catch() handlers.
	 *
	 * $onFulfilledOrRejected will be called, with no arguments, when the
	 * promise is either fulfilled or rejected.
	 */
	public function finally(
		callable $onFulfilledOrRejected
	):PromiseInterface;

	public function getState():string;

	/** @return mixed */
	public function wait(bool $unwrap = true);
}