<?php /** @noinspection PhpUnused */
declare(strict_types=1);

namespace SpaethTech\HTML;

/**
 * @link https://github.com/gajus/dindent for the canonical source repository
 * @license https://github.com/gajus/dindent/blob/master/LICENSE BSD 3-Clause
 */
class Indenter {
    private
        array $log = [],
        $options = [
        'indentation_character' => '    '
    ],
        $inline_elements = ['b', 'big', 'i', 'small', 'tt', 'abbr', 'acronym', 'cite', 'code', 'dfn', 'em', 'kbd', 'strong', 'samp', 'var', 'a', 'bdo', 'br', 'img', 'span', 'sub', 'sup'],
        $temporary_replacements_script = [],
        $temporary_replacements_inline = [];
    const ELEMENT_TYPE_BLOCK = 0;
    const ELEMENT_TYPE_INLINE = 1;
    const MATCH_INDENT_NO = 0;
    const MATCH_INDENT_DECREASE = 1;
    const MATCH_INDENT_INCREASE = 2;
    const MATCH_DISCARD = 3;

    /**
     * @param array $options
     * @throws Exceptions\InvalidArgumentException
     */
    public function __construct (array $options = array()) {
        foreach ($options as $name => $value) {
            if (!array_key_exists($name, $this->options)) {
                throw new Exceptions\InvalidArgumentException('Unrecognized option.');
            }
            $this->options[$name] = $value;
        }
    }

    /**
     * @param string $element_name Element name, e.g. "b".
     * @param int $type
     * @throws Exceptions\InvalidArgumentException
     */
    public function setElementType (string $element_name, int $type) {
        if ($type === static::ELEMENT_TYPE_BLOCK) {
            $this->inline_elements = array_diff($this->inline_elements, array($element_name));
        } else if ($type === static::ELEMENT_TYPE_INLINE) {
            $this->inline_elements[] = $element_name;
        } else {
            throw new Exceptions\InvalidArgumentException('Unrecognized element type.');
        }
        $this->inline_elements = array_unique($this->inline_elements);
    }

    /**
     * @param string $input HTML input.
     * @return string Indented HTML.
     * @throws Exceptions\RuntimeException
     */
    public function indent (string $input): string {
        $this->log = array();
// indent does not indent <script> body. Instead, it temporarily removes it from the code, indents the input, and restores the script body.
        if (preg_match_all('/<script\b[^>]*>([\s\S]*?)<\/script>/mi', $input, $matches)) {
            $this->temporary_replacements_script = $matches[0];
            foreach ($matches[0] as $i => $match) {
                $input = str_replace($match, '<script>' . ($i + 1) . '</script>', $input);
            }
        }
// Removing double whitespaces to make the source code easier to read.
// Except when using <pre>/ CSS white-space changing the default behaviour, double whitespace is meaningless in HTML output.
        // This reason alone is sufficient not to use indent in production.
        $input = str_replace("\t", '', $input);
        $input = preg_replace('/\s{2,}/u', ' ', $input);
        // Remove inline elements and replace them with text entities.
        if (preg_match_all('/<(' . implode('|', $this->inline_elements) . ')[^>]*>[^<]*<\/\1>/', $input, $matches)) {
            $this->temporary_replacements_inline = $matches[0];
            foreach ($matches[0] as $i => $match) {
                $input = str_replace($match, 'ᐃ' . ($i + 1) . 'ᐃ', $input);
            }
        }
        $subject = $input;
        $output = '';
        $next_line_indentation_level = 0;
        $match = null;
        do {
            $indentation_level = $next_line_indentation_level;
            $patterns = array(
                // block tag
                '/^(<([a-z]+)(?:[^>]*)>(?:[^<]*)<\/(?:\2)>)/' => static::MATCH_INDENT_NO,
                // DOCTYPE
                '/^<!([^>]*)>/' => static::MATCH_INDENT_NO,
                // tag with implied closing
                '/^<(input|link|meta|base|br|img|source|hr)([^>]*)>/' => static::MATCH_INDENT_NO,
                // self closing SVG tags
                '/^<(animate|stop|path|circle|line|polyline|rect|use)([^>]*)\/>/' => static::MATCH_INDENT_NO,
                // opening tag
                '/^<[^\/]([^>]*)>/' => static::MATCH_INDENT_INCREASE,
                // closing tag
                '/^<\/([^>]*)>/' => static::MATCH_INDENT_DECREASE,
                // self-closing tag
                '/^<(.+)\/>/' => static::MATCH_INDENT_DECREASE,
                // whitespace
                '/^(\s+)/' => static::MATCH_DISCARD,
                // text node
                '/([^<]+)/' => static::MATCH_INDENT_NO
            );
            $rules = array('NO', 'DECREASE', 'INCREASE', 'DISCARD');
            foreach ($patterns as $pattern => $rule) {
                if ($match = preg_match($pattern, $subject, $matches)) {
                    $this->log[] = array(
                        'rule' => $rules[$rule],
                        'pattern' => $pattern,
                        'subject' => $subject,
                        'match' => $matches[0]
                    );
                    $subject = mb_substr($subject, mb_strlen($matches[0]));
                    if ($rule === static::MATCH_DISCARD) {
                        break;
                    }
                    
                    switch($rule)
                    {
                        case static::MATCH_INDENT_NO:
                            break;
                        case static::MATCH_INDENT_DECREASE:
                            $next_line_indentation_level--;
                            $indentation_level--;
                            break;
                        default:
                            $next_line_indentation_level++;
                            break;
                    }
                    
//                    if ($rule === static::MATCH_INDENT_NO) {
//                    } else if ($rule === static::MATCH_INDENT_DECREASE) {
//                        $next_line_indentation_level--;
//                        $indentation_level--;
//                    } else {
//                        $next_line_indentation_level++;
//                    }
                    if ($indentation_level < 0) {
                        $indentation_level = 0;
                    }
                    $output .= str_repeat($this->options['indentation_character'], $indentation_level) . $matches[0] . "\n";
                    break;
                }
            }
        } while ($match);
        $interpreted_input = '';
        foreach ($this->log as $e) {
            $interpreted_input .= $e['match'];
        }
        if ($interpreted_input !== $input) {
            throw new Exceptions\RuntimeException('Did not reproduce the exact input.');
        }
        $output = preg_replace('/(<(\w+)[^>]*>)\s*(<\/\2>)/u', '\\1\\3', $output);
        foreach ($this->temporary_replacements_script as $i => $original) {
            $output = str_replace('<script>' . ($i + 1) . '</script>', $original, $output);
        }
        foreach ($this->temporary_replacements_inline as $i => $original) {
            $output = str_replace('ᐃ' . ($i + 1) . 'ᐃ', $original, $output);
        }
        return trim($output);
    }
    /**
     * Debugging utility. Get log for the last indent operation.
     *
     * @return array
     */
    public function getLog (): array {
        return $this->log;
    }
}
