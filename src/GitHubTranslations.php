<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1;

/** Loads translations shipped with the physically loaded Provider package. */
final class GitHubTranslations {

	public const DOMAIN = 'ran-booster-github-provider';

	public static function load_current_locale(): bool {
		if ( ! function_exists( 'determine_locale' ) ) {
			return false;
		}

		return self::load( determine_locale() );
	}

	public static function load( string $locale ): bool {
		if ( ! function_exists( 'load_textdomain' ) ) {
			return false;
		}

		return load_textdomain(
			self::DOMAIN,
			dirname( __DIR__ ) . '/languages/' . self::DOMAIN . '-' . $locale . '.mo',
			$locale
		);
	}
}
