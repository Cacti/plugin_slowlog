<?php
/*
 * PHP-CS-Fixer configuration for plugin_slowlog, adapted from Cacti core's own
 * .php-cs-fixer.php (https://github.com/Cacti/cacti/blob/develop/.php-cs-fixer.php) so this
 * plugin's style matches the host application's conventions (tabs, single quotes, short array
 * syntax, same brace/spacing rules). The Finder exclusions are adapted to this plugin's own
 * directory layout instead of Cacti core's.
 */

$finder = PhpCsFixer\Finder::create()
	->exclude('vendor')
	->exclude('tests')
	->exclude('locales')
	->exclude('images')
	->exclude('js')
	->exclude('themes')
	->in(__DIR__);

$config = new PhpCsFixer\Config();
$config
	->setRiskyAllowed(true)
	->setIndent("\t")
	->setLineEnding("\n")
	->setRules(array(
		'header_comment'                               => false,
		'comment_to_phpdoc'                             => true,
		'phpdoc_align'                                  => true,
		'list_syntax'                                   => array('syntax' => 'short'),
		'array_syntax'                                  => array('syntax' => 'short'),
		'trim_array_spaces'                             => false,
		'no_whitespace_before_comma_in_array'           => true,
		'whitespace_after_comma_in_array'               => true,
		'no_multiline_whitespace_around_double_arrow'   => true,
		'no_whitespace_in_blank_line'                   => true,
		'no_trailing_whitespace'                        => true,
		'normalize_index_brace'                         => true,
		'no_mixed_echo_print'                           => array('use' => 'print'),
		'no_spaces_after_function_name'                 => true,
		'braces_position'                               => array(
			'anonymous_classes_opening_brace'   => 'same_line',
			'anonymous_functions_opening_brace' => 'same_line',
			'classes_opening_brace'             => 'same_line',
			'functions_opening_brace'           => 'same_line',
		),
		'single_blank_line_at_eof'                      => true,
		'method_chaining_indentation'                   => true,
		'indentation_type'                              => true,
		'constant_case'                                 => true,
		'lowercase_keywords'                             => true,
		'line_ending'                                    => true,
		'magic_constant_casing'                          => true,
		'native_function_casing'                         => true,
		'elseif'                                         => true,
		'include'                                        => false,
		'no_alternative_syntax'                          => true,
		'no_superfluous_elseif'                          => true,
		'no_trailing_comma_in_singleline'                => true,
		'no_unneeded_braces'                              => true,
		'no_useless_else'                                => false,
		'yoda_style'                                     => array(
			'equal'              => false,
			'identical'          => false,
			'less_and_greater'   => null,
			'always_move_variable' => false,
		),
		'declare_equal_normalize'                        => array('space' => 'single'),
		'dir_constant'                                   => true,
		'single_space_around_construct'                  => array(
			'constructs_followed_by_a_single_space' => array(
				'abstract', 'as', 'attribute', 'break', 'case', 'catch', 'class', 'clone',
				'const', 'const_import', 'continue', 'do', 'echo', 'else', 'elseif',
				'extends', 'final', 'finally', 'for', 'foreach', 'function',
				'function_import', 'global', 'goto', 'if', 'implements', 'instanceof',
				'insteadof', 'interface', 'match', 'named_argument', 'new',
				'open_tag_with_echo', 'php_open', 'print', 'private', 'protected',
				'public', 'return', 'static', 'throw', 'trait', 'try', 'use',
				'use_lambda', 'use_trait', 'var', 'while', 'yield', 'yield_from',
			),
		),
		'concat_space'                                   => array('spacing' => 'one'),
		'switch_case_semicolon_to_colon'                  => true,
		'switch_case_space'                               => true,
		'switch_continue_to_break'                        => true,
		'logical_operators'                               => true,
		'function_declaration'                            => array('closure_function_spacing' => 'one'),
		'spaces_inside_parentheses'                        => true,
		'binary_operator_spaces'                          => array(
			'operators' => array(
				'+='  => 'align_single_space',
				'===' => 'align_single_space_minimal',
				'='   => 'align_single_space',
				'|'   => 'single_space',
				'=>'  => 'align',
				'!='  => 'align',
			),
		),
		'not_operator_with_space'                         => false,
		'no_spaces_around_offset'                          => array('positions' => array('outside', 'inside')),
		'standardize_not_equals'                           => true,
		'ternary_operator_spaces'                          => true,
		'full_opening_tag'                                 => false,
		'linebreak_after_opening_tag'                      => false,
		'phpdoc_add_missing_param_annotation'               => true,
		'no_extra_blank_lines'                              => array(
			'tokens' => array(
				'break', 'case', 'continue', 'curly_brace_block', 'default', 'extra',
				'parenthesis_brace_block', 'return', 'square_brace_block', 'switch',
				'throw', 'use',
			),
		),
		'no_empty_statement'                               => true,
		'multiline_whitespace_before_semicolons'           => true,
		'no_singleline_whitespace_before_semicolons'       => true,
		'semicolon_after_instruction'                      => false,
		'space_after_semicolon'                            => array('remove_in_empty_for_expressions' => true),
		'blank_line_before_statement'                       => array(
			'statements' => array(
				'continue', 'break', 'declare', 'do', 'for', 'foreach', 'goto', 'if',
				'return', 'switch', 'throw', 'try', 'while', 'yield', 'yield_from',
			),
		),
		'explicit_string_variable'                          => false,
		'single_quote'                                       => true,
		'string_line_ending'                                 => true,
		'strict_param'                                       => true,
		'align_multiline_comment'                            => array('comment_type' => 'phpdocs_like'),
		'single_line_comment_spacing'                        => true,
		'single_line_comment_style'                          => true,
		'multiline_comment_opening_closing'                  => true,
	))
	->setFinder($finder);

return $config;
