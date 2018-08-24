<?php
// src/groupedextensions.php -- HotCRP extensible groups
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class GroupedExtensions {
    private $_subgroups;
    private $_render_state;
    private $_render_stack;
    private $_render_classes;
    static private $next_placeholder;

    function _add_json($fj) {
        if (!isset($fj->name)) {
            $fj->name = "__" . self::$next_placeholder . "__";
            ++self::$next_placeholder;
        }
        if (!isset($fj->group)) {
            if (($pos = strrpos($fj->name, "/")) !== false)
                $fj->group = substr($fj->name, 0, $pos);
            else
                $fj->group = $fj->name;
        }
        if (!isset($fj->synonym))
            $fj->synonym = [];
        else if (is_string($fj->synonym))
            $fj->synonym = [$fj->synonym];
        if (!isset($fj->anchorid)
            && !str_starts_with($fj->name, "__")
            && ($pos = strpos($fj->name, "/")) !== false) {
            $x = substr($fj->name, $pos + 1);
            $fj->anchorid = preg_replace('/\A[^A-Za-z]+|[^A-Za-z0-9_:.]+/', "-", strtolower($x));
        }
        $this->_subgroups[] = $fj;
        return true;
    }
    function __construct(Contact $user, $args /* ... */) {
        self::$next_placeholder = 1;
        $this->_subgroups = [];
        foreach (func_get_args() as $i => $arg) {
            if ($i > 0 && $arg)
                expand_json_includes_callback($arg, [$this, "_add_json"]);
        }
        usort($this->_subgroups, "Conf::xt_priority_compare");
        $sgs = [];
        foreach ($this->_subgroups as $gj) {
            if ($user->conf->xt_allowed($gj, $user)) {
                if (isset($sgs[$gj->name])) {
                    $pgj = $sgs[$gj->name];
                    if (isset($pgj->merge) && $pgj->merge) {
                        unset($pgj->merge);
                        $sgs[$gj->name] = object_replace_recursive($gj, $pgj);
                    }
                } else
                    $sgs[$gj->name] = $gj;
            }
        }
        $this->_subgroups = [];
        foreach ($sgs as $name => $gj) {
            if (Conf::xt_enabled($gj))
                $this->_subgroups[$name] = $gj;
        }
        uasort($this->_subgroups, function ($aj, $bj) {
            if ($aj->group !== $bj->group) {
                if (isset($this->_subgroups[$aj->group]))
                    $aj = $this->_subgroups[$aj->group];
                if (isset($this->_subgroups[$bj->group]))
                    $bj = $this->_subgroups[$bj->group];
            }
            return Conf::xt_position_compare($aj, $bj);
        });
    }
    function get($name) {
        if (isset($this->_subgroups[$name]))
            return $this->_subgroups[$name];
        foreach ($this->_subgroups as $gj) {
            if (in_array($name, $gj->synonym))
                return $gj;
        }
        return null;
    }
    function canonical_group($name) {
        $gj = $this->get($name);
        return $gj ? $gj->group : false;
    }
    function members($name) {
        if ((string) $name === "")
            return $this->groups();
        if (($subgroup = str_ends_with($name, "/*")))
            $name = substr($name, 0, -2);
        if (($gj = $this->get($name)))
            $name = $gj->name;
        return array_filter($this->_subgroups, function ($gj) use ($name, $subgroup) {
            return $gj->name === $name ? !$subgroup : $gj->group === $name;
        });
    }
    function all() {
        return $this->_subgroups;
    }
    function groups() {
        return array_filter($this->_subgroups, function ($gj) {
            return $gj->name === $gj->group;
        });
    }

    function reset_render() {
        assert(!isset($this->_render_state));
        $this->_render_classes = null;
    }
    function start_render($heading_number = 3, $heading_class = null) {
        $this->_render_stack[] = $this->_render_state;
        $this->_render_state = [null, $heading_number, $heading_class];
    }
    function end_render() {
        assert(!empty($this->_render_stack));
        $this->_render_state = array_pop($this->_render_stack);
    }
    function render($gj, $args) {
        assert($this->_render_state !== null);
        if (isset($gj->title)
            && $gj->title !== $this->_render_state[0]
            && $gj->group !== $gj->name) {
            echo '<h', $this->_render_state[1];
            if ($this->_render_state[2])
                echo ' class="', $this->_render_state[2], '"';
            if (isset($gj->anchorid))
                echo ' id="', htmlspecialchars($gj->anchorid), '"';
            echo '>', $gj->title, "</h", $this->_render_state[1], ">\n";
            $this->_render_state[0] = $gj->title;
        }
        if (isset($gj->render_callback)) {
            Conf::xt_resolve_require($gj);
            $cb = $gj->render_callback;
            if ($cb[0] === "*") {
                $colons = strpos($cb, ":");
                $klass = substr($cb, 1, $colons - 1);
                if (!$this->_render_classes
                    || !isset($this->_render_classes[$klass]))
                    $this->_render_classes[$klass] = new $klass(...$args);
                $cb = [$this->_render_classes[$klass], substr($cb, $colons + 2)];
            }
            call_user_func_array($cb, $args);
        } else if (isset($gj->render_html))
            echo $gj->render_html;
    }

    static function xt_request_allowed($expr, $xt, Qrequest $qreq, Contact $user) {
        foreach (is_array($expr) ? $expr : [$expr] as $e) {
            $not = false;
            if (is_string($e) && ($not = str_starts_with($e, "!")))
                $e = substr($e, 1);
            if (!is_string($e))
                $b = $e;
            else if ($e === "post")
                $b = $qreq->method() === "POST" && $qreq->post_ok();
            else if ($e === "getpost")
                $b = ($qreq->method() === "GET" || $qreq->method() === "POST") && $qreq->post_ok();
            else if (in_array($e, ["chair", "admin", "manager", "pc", "reviewer"])
                     || strpos($e, "::") !== false
                     || str_starts_with($e, "opt.")
                     || str_starts_with($e, "setting."))
                $b = $user->conf->xt_check($e, $xt, $user);
            else {
                $b = false;
                foreach (explode(" ", $e) as $word)
                    $b = $b || isset($qreq[$word]);
            }
            if ($not ? $b : !$b)
                return false;
        }
        return true;
    }
}
