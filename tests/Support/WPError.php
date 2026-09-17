<?php

declare(strict_types=1);

if ( class_exists( 'WP_Error', false ) ) {
	return;
}

final class WP_Error {

	public function __construct(
		private string $code,
		private string $message,
		private mixed $data = null
	) {
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}

	public function get_error_data(): mixed {
		return $this->data;
	}
}
