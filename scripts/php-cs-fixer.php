<?php
/**
 * PHP CS Fixer configuration for the provider package.
 *
 * @package RAN\BoosterGitHubProvider
 */

declare(strict_types=1);

use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

$finder = PhpCsFixer\Finder::create()
	->exclude( 'vendor' )
	->in( dirname( __DIR__ ) );

$config          = new PhpCsFixer\Config();
$parallel_config = ParallelConfigFactory::detect();

return $config
	->setParallelConfig( $parallel_config )
	->setRules(
		array(
			'line_ending'                       => true,
			'no_trailing_whitespace'            => true,
			'no_trailing_whitespace_in_comment' => true,
			'single_quote'                      => true,
			'array_syntax'                      => array( 'syntax' => 'long' ),
			'no_whitespace_before_comma_in_array' => true,
			'whitespace_after_comma_in_array'     => true,
			'concat_space'                        => array( 'spacing' => 'one' ),
			'binary_operator_spaces'              => array(
				'default'   => 'align_single_space_minimal',
				'operators' => array(
					'=>' => 'align_single_space_minimal',
					'='  => 'align_single_space_minimal',
				),
			),
			'braces'                              => array(
				'position_after_functions_and_oop_constructs' => 'same',
				'position_after_control_structures'           => 'same',
				'position_after_anonymous_constructs'         => 'same',
			),
			'method_chaining_indentation'          => false,
			'statement_indentation'                => false,
			'array_indentation'                    => false,
			'indentation_type'                     => false,
		)
	)
	->setIndent( "\t" )
	->setLineEnding( "\n" )
	->setUsingCache( false )
	->setFinder( $finder );
