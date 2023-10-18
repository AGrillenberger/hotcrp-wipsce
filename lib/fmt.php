<?php
// fmt.php -- HotCRP helper functions for message formatting i18n
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class FmtArg {
    /** @var int|string */
    public $name;
    /** @var mixed */
    public $value;
    /** @var ?int */
    public $format;

    /** @param int|string $name
     * @param ?int $format */
    function __construct($name, $value, $format = null) {
        $this->name = $name;
        $this->value = $value;
        $this->format = $format;
    }

    /** @param ?int $format
     * @return string */
    function resolve_as($format) {
        if ($format !== null
            && $this->format !== null
            && $format !== $this->format) {
            return Ftext::convert($this->value, $this->format, $format);
        }
        return $this->value;
    }
}

class FmtItem {
    /** @var ?string */
    public $context;
    /** @var string */
    public $out;
    /** @var ?list<string> */
    public $require;
    /** @var float */
    public $priority = 0.0;
    /** @var bool */
    public $no_conversions = false;
    /** @var bool */
    public $template = false;
    /** @var ?FmtItem */
    public $next;

    /** @param list<string> $args
     * @param string $s
     * @param ?string &$val
     * @return bool */
    private function resolve_arg(Fmt $ms, $args, $s, &$val) {
        $pos = 0;
        $len = strlen($s);
        if ($pos !== $len && $s[$pos] === "#") {
            $iscount = true;
            ++$pos;
        } else {
            $iscount = false;
        }

        if ($pos !== $len
            && $s[$pos] === "{"
            && preg_match('/\{(0|[1-9]\d*|[A-Za-z_]\w*)(|\[[^\]]+\])\}\z/A', $s, $m, 0, $pos)) {
            if (($fa = Fmt::find_arg($args, ctype_digit($m[1]) ? intval($m[1]) : $m[1]))) {
                $val = $fa->value;
            } else {
                return false;
            }
            $component = $m[2] === "" ? null : substr($m[2], 1, -1);
        } else if ($pos !== $len
                   && $s[$pos] === "\$"
                   && preg_match('/\$([1-9]\d*)(|\[[^\]]+\])\z/A', $s, $m, 0, $pos)) {
            if (($fa = Fmt::find_arg($args, intval($m[1]) - 1))) {
                $val = $fa->value;
            } else {
                return false;
            }
            $component = $m[2] === "" ? null : substr($m[2], 1, -1);
        } else {
            if (($bpos = strpos($s, "[", $pos)) !== false
                && $bpos !== $len - 1
                && $s[$len - 1] === "]") {
                $val = $ms->resolve_requirement_argument(substr($s, $pos, $bpos - $pos));
                $component = substr($s, $bpos + 1, $len - $bpos - 2);
            } else {
                $val = $ms->resolve_requirement_argument($s);
                $component = null;
            }
        }

        if ($component !== null) {
            if (is_array($val)) {
                $val = $val[$component] ?? null;
            } else if (is_object($val)) {
                $val = $val->$component ?? null;
            } else {
                return false;
            }
        }

        if ($iscount) {
            if (is_array($val)) {
                $val = count($val);
            } else {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $args
     * @return int|false */
    function check_require(Fmt $ms, $args) {
        if (!$this->require) {
            return 0;
        }
        $nreq = 0;
        $compval = null;
        '@phan-var-force ?string $compval';
        foreach ($this->require as $req) {
            if (preg_match('/\A\s*(!*)\s*(\S+?)\s*(\z|[=!<>]=?|≠|≤|≥|!?\^=)\s*(\S*)\s*\z/', $req, $m)
                && ($m[1] === "" || ($m[3] === "" && $m[4] === ""))
                && ($m[3] === "") === ($m[4] === "")) {
                if (!$this->resolve_arg($ms, $args, $m[2], $val)) {
                    return false;
                }
                $compar = $m[3];
                $compval = $m[4];
                if ($m[4] !== ""
                    && ($m[4][0] === "\$" || $m[4][0] === "{")
                    && !$this->resolve_arg($ms, $args, $m[4], $compval)) {
                    return false;
                }
                if ($compar === "") {
                    $bval = (bool) $val && $val !== "0";
                    $weight = $bval === (strlen($m[1]) % 2 === 0) ? 1 : 0;
                } else if (!is_scalar($val)) {
                    $weight = 0;
                } else if ($compar === "^=") {
                    $weight = str_starts_with($val, $compval) ? 0.9 : 0;
                } else if ($compar === "!^=") {
                    $weight = !str_starts_with($val, $compval) ? 0.9 : 0;
                } else if (is_numeric($compval)) {
                    $weight = CountMatcher::compare((float) $val, $compar, (float) $compval) ? 1 : 0;
                } else if ($compar === "=" || $compar === "==") {
                    $weight = (string) $val === (string) $compval ? 1 : 0;
                } else if ($compar === "!=" || $compar === "≠") {
                    $weight = (string) $val === (string) $compval ? 0 : 1;
                } else {
                    $weight = 0;
                }
                if ($weight === 0) {
                    return false;
                }
                $nreq += $weight;
            } else {
                return false;
            }
        }
        return $nreq;
    }
}

class FmtContext {
    /** @var list */
    public $args;
    /** @var int */
    public $argnum = 0;
    /** @var ?string */
    public $context;
    /** @var ?FmtItem */
    public $im;
    /** @var ?int */
    public $format;

    function __construct($args, $context, $im, $format) {
        $this->args = $args;
        $this->context = $context;
        $this->im = $im;
        $this->format = $format;
    }
}

class Fmt {
    /** @var array<string,FmtItem> */
    private $ims = [];
    /** @var list<callable(string):(false|array{true,mixed})> */
    private $require_resolvers = [];
    private $_context_prefix;
    private $_default_priority;

    const PRIO_OVERRIDE = 1000.0;

    /** @param int|float $p */
    function set_default_priority($p) {
        $this->_default_priority = (float) $p;
    }

    function clear_default_priority() {
        $this->_default_priority = null;
    }

    function add($m, $ctx = null) {
        if (is_string($m)) {
            $x = $this->addj(func_get_args());
        } else if (!$ctx) {
            $x = $this->addj($m);
        } else {
            $octx = $this->_context_prefix;
            $this->_context_prefix = $ctx;
            $x = $this->addj($m);
            $this->_context_prefix = $octx;
        }
        return $x;
    }

    /** @param object $m
     * @return bool */
    private function _addj_object($m) {
        if (isset($m->members) && is_array($m->members)) {
            $octx = $this->_context_prefix;
            $oprio = $this->_default_priority;
            if (isset($m->context) && is_string($m->context)) {
                $cp = $this->_context_prefix ?? "";
                $this->_context_prefix = ($cp === "" ? $m->context : $cp . "/" . $m->context);
            }
            if (isset($m->priority) && (is_int($m->priority) || is_float($m->priority))) {
                $this->_default_priority = (float) $m->priority;
            }
            $ret = true;
            foreach ($m->members as $mm) {
                $ret = $this->addj($mm) && $ret;
            }
            $this->_context_prefix = $octx;
            $this->_default_priority = $oprio;
            return $ret;
        } else {
            $im = new FmtItem;
            if (isset($m->context) && is_string($m->context)) {
                $im->context = $m->context;
            }
            if (isset($m->id) /* XXX */) {
                $in = $m->id;
                $im->out = $m->otext ?? $m->itext ?? null;
            } else {
                $in = $m->in ?? $m->itext /* XXX */ ?? null;
                $im->out = $m->out ?? $m->otext /* XXX */ ?? $in;
            }
            if (!is_string($in) || !is_string($im->out)) {
                return false;
            }
            if (isset($m->priority) && (is_float($m->priority) || is_int($m->priority))) {
                $im->priority = (float) $m->priority;
            }
            if (isset($m->require) && is_array($m->require)) {
                $im->require = $m->require;
            }
            if (isset($m->no_conversions) && is_bool($m->no_conversions)) {
                $im->no_conversions = $m->no_conversions;
            }
            if (isset($m->template) && is_bool($m->template)) {
                $im->template = $m->template;
            }
            $this->_addj_finish($in, $im);
            return true;
        }
    }

    /** @param array{string,string} $m */
    private function _addj_list($m) {
        $im = new FmtItem;
        $n = count($m);
        $p = false;
        while ($n > 0 && !is_string($m[$n - 1])) {
            if ((is_int($m[$n - 1]) || is_float($m[$n - 1])) && $p === false) {
                $p = $im->priority = (float) $m[$n - 1];
            } else if (is_array($m[$n - 1]) && $im->require === null) {
                $im->require = $m[$n - 1];
            } else {
                return false;
            }
            --$n;
        }
        if ($n < 2 || $n > 3 || !is_string($m[0]) || !is_string($m[1])
            || ($n === 3 && !is_string($m[2]))) {
            return false;
        }
        if ($n === 3) {
            $im->context = $m[0];
            $in = $m[1];
            $im->out = $m[2];
        } else {
            $in = $m[0];
            $im->out = $m[1];
        }
        $this->_addj_finish($in, $im);
        return true;
    }

    /** @param string $in
     * @param FmtItem $im */
    private function _addj_finish($in, $im) {
        if ($this->_context_prefix) {
            $im->context = $this->_context_prefix . ($im->context ? "/" . $im->context : "");
        }
        $im->priority = $im->priority ?? $this->_default_priority;
        $im->next = $this->ims[$in] ?? null;
        $this->ims[$in] = $im;
    }

    /** @param array{string,string}|array{string,string,int}|object|array<string,mixed> $m */
    function addj($m) {
        if (is_associative_array($m)) {
            return $this->_addj_object((object) $m);
        } else if (is_array($m)) {
            return $this->_addj_list($m);
        } else if (is_object($m)) {
            return $this->_addj_object($m);
        } else {
            return false;
        }
    }

    /** @param string $in
     * @param string $out
     * @param null|float|int $priority */
    function define_template($in, $out, $priority = null) {
        $this->_addj_object((object) ["in" => $in, "out" => $out, "priority" => $priority, "template" => true]);
    }

    /** @param string $in
     * @return bool */
    function has_override($in) {
        $im = $this->ims[$in] ?? null;
        return $im && $im->priority === self::PRIO_OVERRIDE;
    }

    /** @param string $in
     * @param string $out */
    function add_override($in, $out) {
        $im = $this->ims[$in] ?? null;
        return $this->addj(["in" => $in, "out" => $out, "priority" => self::PRIO_OVERRIDE, "no_conversions" => true, "template" => $im && $im->template]);
    }

    function remove_overrides() {
        $ids = [];
        foreach ($this->ims as $id => $im) {
            if ($im->priority >= self::PRIO_OVERRIDE)
                $ids[] = $id;
        }
        foreach ($ids as $id) {
            while (($im = $this->ims[$id]) && $im->priority >= self::PRIO_OVERRIDE) {
                $this->ims[$id] = $im->next;
            }
            if (!$im) {
                unset($this->ims[$id]);
            }
        }
    }

    /** @param callable(string):(false|array{true,mixed}) $function */
    function add_requirement_resolver($function) {
        $this->require_resolvers[] = $function;
    }

    /** @param string $s */
    function resolve_requirement_argument($s) {
        foreach ($this->require_resolvers as $fn) {
            if (($v = call_user_func($fn, $s)))
                return $v[1];
        }
        return null;
    }

    /** @param ?string $context
     * @param string $in
     * @param list<mixed> $args
     * @param ?float $priobound
     * @return ?FmtItem */
    private function find($context, $in, $args, $priobound) {
        $match = null;
        $matchnreq = $matchctxlen = 0;
        if ($context === "") {
            $context = null;
        }
        for ($im = $this->ims[$in] ?? null; $im; $im = $im->next) {
            $ctxlen = $nreq = 0;
            if ($context !== null && $im->context !== null) {
                if ($context === $im->context) {
                    $ctxlen = 10000;
                } else {
                    $ctxlen = strlen($im->context);
                    if ($ctxlen > strlen($context)
                        || strncmp($context, $im->context, $ctxlen) !== 0
                        || $context[$ctxlen] !== "/") {
                        continue;
                    }
                }
            } else if ($context === null && $im->context !== null) {
                continue;
            }
            if ($im->require
                && ($nreq = $im->check_require($this, $args)) === false) {
                continue;
            }
            if ($priobound !== null
                && $im->priority >= $priobound) {
                continue;
            }
            if (!$match
                || $im->priority > $match->priority
                || ($im->priority == $match->priority
                    && ($ctxlen > $matchctxlen
                        || ($ctxlen == $matchctxlen
                            && $nreq > $matchnreq)))) {
                $match = $im;
                $matchnreq = $nreq;
                $matchctxlen = $ctxlen;
            }
        }
        return $match;
    }

    /** @param list<mixed> $args
     * @param int|string $argdef
     * @return ?FmtArg */
    static function find_arg($args, $argdef) {
        $arg = null;
        if (is_string($argdef)) {
            foreach ($args as $arg) {
                if ($arg instanceof FmtArg
                    && strcasecmp($arg->name, $argdef) === 0) {
                    return $arg;
                }
            }
        } else if (is_int($argdef) && $argdef >= 0 && $argdef < count($args)) {
            $arg = $args[$argdef];
            if (!($arg instanceof FmtArg)) {
                return new FmtArg($argdef, $arg);
            } else if ($arg->name === $argdef) {
                return $arg;
            }
        }
        return null;
    }

    /** @param string $s
     * @param int $pos
     * @param FmtContext $fctx
     * @return array{int,?string} */
    private function expand_percent($s, $pos, $fctx) {
        if (preg_match('/%((?!\d)\w+)%/A', $s, $m, 0, $pos)) {
            if (($fa = self::find_arg($fctx->args, strtolower($m[1])))) {
                return [$pos + strlen($m[0]), $fa->resolve_as($fctx->format)];
            } else if (($imt = $this->find($fctx->context, strtolower($m[1]), [$m[1]], null))
                       && $imt->template) {
                return [$pos + strlen($m[0]), $this->expand($imt->out, $fctx->args, null, null)];
            }
        }

        if (!($fctx->im && $fctx->im->no_conversions)
            && count($fctx->args) > 0
            && preg_match('/%(?:(\d+)(\[[^\[\]\$]*\]|)\$|)(#[AON]?|)(\d*(?:\.\d+|))([deEifgosxXHU])/A', $s, $m, 0, $pos)) {
            $argi = $m[1] ? +$m[1] : ++$fctx->argnum;
            if (($fa = self::find_arg($fctx->args, $argi - 1))) {
                $val = $fa->resolve_as($fctx->format);
                if ($m[2]) {
                    assert(is_array($val));
                    $val = $val[substr($m[2], 1, -1)] ?? null;
                }
                if ($m[3] && is_array($val)) {
                    if ($m[3] === "#N") {
                        $val = numrangejoin($val);
                    } else if ($m[3] === "#O") {
                        $val = commajoin($val, "or");
                    } else {
                        $val = commajoin($val, "and");
                    }
                }
                $conv = $m[4];
                if ($m[5] === "H") {
                    $x = htmlspecialchars($conv === "" ? $val : sprintf("%{$conv}s", $val));
                } else if ($m[5] === "U") {
                    $x = urlencode($conv === "" ? $val : sprintf("%{$conv}s", $val));
                } else if ($m[5] === "s" && $conv === "") {
                    $x = (string) $val;
                } else {
                    $x = sprintf("%{$conv}{$m[5]}", $val);
                }
                return [$pos + strlen($m[0]), $x];
            }
        }

        return [$pos + 1, null];
    }

    /** @param string $s
     * @param int $pos
     * @param FmtContext $fctx
     * @return array{int,?string} */
    private function expand_brace($s, $pos, $fctx) {
        if (!preg_match('/\{(|0|[1-9]\d*+|[a-zA-Z_]\w*+)(|\[[^\]]*+\])(|:(?:[^\}]|\}\})*+)\}/A', $s, $m, 0, $pos)
            || ($m[1] === "" && ($fctx->argnum === null || $m[2] !== ""))
            || ($fctx->im && $fctx->im->no_conversions && ($m[1] === "" || ctype_digit($m[1])))) {
            return [$pos + 1, $s];
        }
        if ($m[1] === "") {
            $fa = self::find_arg($fctx->args, $fctx->argnum);
            ++$fctx->argnum;
        } else if (ctype_digit($m[1])) {
            $fa = self::find_arg($fctx->args, intval($m[1]));
        } else {
            $fa = self::find_arg($fctx->args, strtolower($m[1]));
        }
        if (!$fa
            && ($imt = $this->find($fctx->context, strtolower($m[1]), [$m[1]], null))
            && $imt->template) {
            list($fmt, $out) = Ftext::parse($this->expand($imt->out, $fctx->args, null, null));
            $fa = new FmtArg("", $out, $fmt);
        }
        if (!$fa) {
            return [$pos + 1, $s];
        }
        if ($m[2]) {
            assert(is_array($fa->value));
            $value = $fa->value[substr($m[2], 1, -1)] ?? null;
        } else {
            $value = $fa->value;
        }
        $vformat = $fa->format;
        $cformat = $fctx->format;
        if ($cformat === null && $pos !== 0) {
            $cformat = Ftext::format($s);
        }
        if ($m[3] === ":url") {
            $value = urlencode($value);
            $vformat = null;
        } else if ($m[3] === ":html") { // unneeded if FmtArg has correct format
            $value = htmlspecialchars($value);
            $vformat = null;
        } else if ($m[3] === ":list") {
            assert(is_array($value));
            $value = commajoin($value);
        } else if ($m[3] === ":lcrestlist") {
            assert(is_array($value));
            for ($i = 1; $i < count($value); ++$i) {
                if (is_string($value[$i])) {
                    $value[$i] = strtolower($value[$i]);
                }
            }
            $value = commajoin($value);
        } else if ($m[3] === ":nblist") {
            assert(is_array($value));
            $value = array_values($value);
            $n = count($value);
            $xvalue = "";
            for ($i = 0; $i !== $n; ++$i) {
                $v = $value[$i];
                if ($i < $n - 1 && $n > 2) {
                    $v = $cformat === 5 ? "<span class=\"nb\">{$v},</span>" : "{$v},";
                } else {
                    $v = $cformat === 5 ? "<span class=\"nb\">{$v}</span>" : $v;
                }
                if ($i < $n - 1) {
                    $v .= $i < $n - 2 ? " " : " and ";
                }
                $xvalue .= $v;
            }
            $value = $xvalue;
        } else if ($m[3] === ":numlist") {
            assert(is_array($value));
            $value = numrangejoin($value);
        } else if ($m[3] === ":humanize_url") {
            if (preg_match('/\Ahttps?:\/\/([^\[\]:\/?#\s]*)([\/?#]\S*|)\z/i', $value, $mm)) {
                $value = $mm[1] . ($mm[2] === "/" ? "" : $mm[2]);
            }
        } else if ($m[3] === ":ftext") {
            if ($vformat === null) {
                list($vformat, $value) = Ftext::parse($value, 0);
            }
            if ($pos === 0 && $cformat === null) {
                $value = "<{$vformat}>{$value}";
            }
        }
        if ($cformat !== null
            && $vformat !== null
            && $cformat !== $vformat) {
            $value = Ftext::convert($value, $vformat, $cformat);
        }
        return [$pos + strlen($m[0]), $value];
    }

    /** @param string $s
     * @param list<mixed> $args
     * @param ?string $context
     * @param ?FmtItem $im
     * @param ?int $format
     * @return string */
    private function expand($s, $args, $context, $im, $format = null) {
        if ($s === null || $s === false || $s === "") {
            return $s;
        }
        $pos = $bpos = 0;
        $len = strlen($s);
        $ppos = $lpos = $rpos = -1;
        if ($format === null && str_starts_with($s, "<")) {
            $format = Ftext::format($s);
        }
        $fctx = new FmtContext($args, $context, $im, $format);
        $t = "";

        while (true) {
            if ($ppos < $pos) {
                $ppos = strpos($s, "%", $pos);
                $ppos = $ppos !== false ? $ppos : $len;
            }
            if ($lpos < $pos) {
                $lpos = strpos($s, "{", $pos);
                $lpos = $lpos !== false ? $lpos : $len;
            }
            if ($rpos < $pos) {
                $rpos = strpos($s, "}", $pos);
                $rpos = $rpos !== false ? $rpos : $len;
            }

            $pos = min($ppos, $lpos, $rpos);
            if ($pos === $len) {
                return $t . substr($s, $bpos);
            }

            $x = null;
            $npos = $pos + 1;
            if ($npos < $len && $s[$pos] === $s[$npos]) {
                if (!$im || !$im->no_conversions) {
                    $x = $s[$pos];
                    ++$npos;
                }
            } else if ($pos === $ppos) {
                list($npos, $x) = $this->expand_percent($s, $pos, $fctx);
            } else if ($pos === $lpos) {
                list($npos, $x) = $this->expand_brace($s, $pos, $fctx);
            }

            if ($x !== null) {
                $t .= substr($s, $bpos, $pos - $bpos) . $x;
                $bpos = $npos;
            }
            $pos = $npos;
        }
    }

    /** @param string $in
     * @return string */
    function _($in, ...$args) {
        if (($im = $this->find(null, $in, $args, null))) {
            $in = $im->out;
        }
        return $this->expand($in, $args, null, $im);
    }

    /** @param string $context
     * @param string $in
     * @return string */
    function _c($context, $in, ...$args) {
        if (($im = $this->find($context, $in, $args, null))) {
            $in = $im->out;
        }
        return $this->expand($in, $args, $context, $im);
    }

    /** @param string $id
     * @return ?string */
    function _i($id, ...$args) {
        $im = $this->find(null, $id, $args, null);
        return $im ? $this->expand($im->out, $args, $id, $im) : null;
    }

    /** @param string $context
     * @param string $id
     * @return ?string */
    function _ci($context, $id, ...$args) {
        $im = $this->find($context, $id, $args, null);
        $cid = (string) $context === "" ? $id : "{$context}/{$id}";
        return $im ? $this->expand($im->out, $args, $cid, $im) : null;
    }

    /** @param FieldRender $fr
     * @param string $context
     * @param string $id */
    function render_ci($fr, $context, $id, ...$args) {
        if (($im = $this->find($context, $id, $args, null))) {
            list($fmt, $out) = Ftext::parse($im->out);
            if ($fmt !== null) {
                $fr->value_format = $fmt;
            }
            $cid = (string) $context === "" ? $id : "{$context}/{$id}";
            $fr->value = $this->expand($out, $args, $cid, $im, $fr->value_format);
        }
    }

    /** @param string $id
     * @return ?string */
    function default_itext($id, ...$args) {
        $im = $this->find(null, $id, $args, self::PRIO_OVERRIDE);
        return $im ? $im->out : null;
    }

    /** @param string $text
     * @return string */
    static function simple($text, ...$args) {
        return (new Fmt)->expand($text, $args, null, null);
    }
}
