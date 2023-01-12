<?php
// help/h_keywords.php -- HotCRP help functions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Keywords_HelpTopic {
    /** @param list<SearchExample> $exs */
    static function print_search_examples(HelpRenderer $hth, $exs) {
        while (($ex = array_shift($exs))) {
            $desc = Ftext::unparse_as($hth->conf->_($ex->description, ...$ex->all_arguments()), 5);
            $qs = [];
            foreach (SearchExample::remove_category($exs, $ex) as $oex) {
                if (!$oex->primary_only)
                    $qs[] = preg_replace('/\{(\w+)\}/', '<i>$1</i>', htmlspecialchars($oex->q));
            }
            foreach ($ex->hints ?? [] as $h) {
                $desc .= '<div class="hint">' . Ftext::unparse_as($hth->conf->_($h, ...$ex->all_arguments()), 5) . '</div>';
            }
            if ($qs) {
                $desc .= '<div class="hint">Also ' . join(", ", $qs) . '</div>';
            }
            echo $hth->search_trow($ex->expanded_query(), $desc);
        }
    }

    static function print(HelpRenderer $hth) {
        // how to report author searches?
        if ($hth->conf->submission_blindness() === Conf::BLIND_NEVER) {
            $aunote = "";
        } else if ($hth->conf->submission_blindness() === Conf::BLIND_ALWAYS) {
            $aunote = "<br><div class=\"hint\">Search uses fields visible to the searcher. For example, PC member searches do not examine authors.</div>";
        } else {
            $aunote = "<br><div class=\"hint\">Search uses fields visible to the searcher. For example, PC member searches do not examine anonymous authors.</div>";
        }

        // does a reviewer tag exist?
        $retag = $hth->meaningful_pc_tag() ?? "";

        echo $hth->table(true);
        echo $hth->tgroup("Basics");
        echo $hth->search_trow("", "all submissions in the search category");
        echo $hth->search_trow("story", "“story” in title, abstract, authors$aunote");
        echo $hth->search_trow("119", "submission #119");
        echo $hth->search_trow("1 2 5 12-24 kernel", "submissions in the numbered set with “kernel” in title, abstract, authors");
        echo $hth->search_trow("\"802\"", "“802” in title, abstract, authors (not submission #802)");
        echo $hth->search_trow("very new", "“very” <em>and</em> “new” in title, abstract, authors");
        echo $hth->search_trow("very AND new", "the same");
        echo $hth->search_trow("\"very new\"", "the phrase “very new” in title, abstract, authors");
        echo $hth->search_trow("very OR new", "<em>either</em> “very” <em>or</em> “new” in title, abstract, authors");
        echo $hth->search_trow("(very AND new) OR newest", "use parentheses to group");
        echo $hth->search_trow("very -new", "“very” <em>but not</em> “new” in title, abstract, authors");
        echo $hth->search_trow("very NOT new", "the same");
        echo $hth->search_trow("ve*", "words that <em>start with</em> “ve” in title, abstract, authors");
        echo $hth->search_trow("*me*", "words that <em>contain</em> “me” in title, abstract, authors");

        echo $hth->tgroup("Title");
        echo $hth->search_trow("ti:flexible", "title contains “flexible”");
        echo $hth->tgroup("Abstract");
        echo $hth->search_trow("ab:\"very novel\"", "abstract contains “very novel”");
        echo $hth->tgroup("Authors");
        echo $hth->search_trow("au:poletto", "author list contains “poletto”");
        if ($hth->user->isPC) {
            echo $hth->search_trow("au:pc", "one or more authors are PC members (author email matches PC email)");
        }
        echo $hth->search_trow("au:>4", "more than four authors");
        echo $hth->tgroup("Collaborators");
        echo $hth->search_trow("co:liskov", "collaborators contains “liskov”");
        echo $hth->tgroup("Topics");
        echo $hth->search_trow("topic:link", "selected topics match “link”");

        $opts = array_filter($hth->conf->options()->normal(), function ($o) {
            return $o->form_order() !== false && $o->search_keyword() !== false;
        });
        usort($opts, function ($a, $b) {
            if ($a->final !== $b->final) {
                return $a->final ? 1 : -1;
            } else {
                return PaperOption::compare($a, $b);
            }
        });
        $exs = [];
        foreach ($opts as $o) {
            foreach ($o->search_examples($hth->user, SearchExample::HELP) as $ex) {
                $exs[] = $ex;
            }
        }

        if (!empty($exs)) {
            echo $hth->tgroup("Submission fields");
            self::print_search_examples($hth, $exs);
        }

        echo $hth->tgroup($hth->help_link("Tags", "tags"));
        echo $hth->search_trow("#discuss", "tagged “discuss” (“tag:discuss” also works)");
        echo $hth->search_trow("-#discuss", "not tagged “discuss”");
        echo $hth->search_trow("order:discuss", "tagged “discuss”, sort by tag order (“rorder:” for reverse order)");
        echo $hth->search_trow("#disc*", "matches any tag that <em>starts with</em> “disc”");

        $cx = null;
        $cm = [];
        foreach ($hth->conf->tags() as $t) {
            foreach ($t->styles ?? [] as $c) {
                $cx = $cx ?? $c;
                if ($cx === $c)
                    $cm[] = "“{$t->tag}”";
            }
        }
        if (!empty($cm)) {
            array_unshift($cm, "“{$cx->name}”");
            $klass = "taghh tag-{$cx->style}";
            if (($cx->sclass & TagStyle::BG) !== 0) {
                $klass .= $cx->dark() ? " dark tagbg" : " tagbg";
            }
            echo $hth->search_trow("style:{$cx->name}", "tagged to appear <span class=\"{$klass}\">{$cx->name}</span> (tagged " . commajoin($cm, "or") . ")");
        }

        $roundname = $hth->meaningful_review_round_name();

        echo $hth->tgroup("Reviews");
        echo $hth->search_trow("re:me", "you are a reviewer");
        echo $hth->search_trow("re:fdabek", "“fdabek” in reviewer name/email");
        if ($retag) {
            echo $hth->search_trow("re:#$retag", "has a reviewer tagged “#" . $retag . "”");
        }
        echo $hth->search_trow("re:4", "four reviewers (assigned and/or completed)");
        if ($retag) {
            echo $hth->search_trow("re:#$retag>1", "at least two reviewers (assigned and/or completed) tagged “#" . $retag . "”");
        }
        echo $hth->search_trow("re:complete<3", "less than three completed reviews<br><div class=\"hint\">Use “cre:<3” for short.</div>");
        echo $hth->search_trow("re:incomplete>0", "at least one incomplete review");
        echo $hth->search_trow("re:inprogress", "at least one in-progress review (started, but not completed)");
        echo $hth->search_trow("re:primary>=2", "at least two primary reviewers");
        echo $hth->search_trow("re:secondary", "at least one secondary reviewer");
        echo $hth->search_trow("re:external", "at least one external reviewer");
        echo $hth->search_trow("re:primary:fdabek:complete", "“fdabek” has completed a primary review");
        if ($hth->conf->setting("extrev_chairreq") >= 0) {
            echo $hth->search_trow("re:myreq", "has a review requested by you");
            echo $hth->search_trow("re:myreq:not-accepted", "has a review requested by you that hasn’t been accepted or edited yet");
        }
        if ($roundname) {
            echo $hth->search_trow("re:{$roundname}", "review in round “" . htmlspecialchars($roundname) . "”");
            echo $hth->search_trow("re:{$roundname}:jinyang", "review in round “" . htmlspecialchars($roundname) . "” by reviewer “jinyang”");
        }
        echo $hth->search_trow("re:auwords<100", "has a review with less than 100 words in author-visible fields");
        if ($hth->conf->setting("rev_tokens")) {
            echo $hth->search_trow("retoken:J88ADNAB", "has a review with token J88ADNAB");
        }
        if ($hth->conf->setting("rev_ratings") != REV_RATINGS_NONE) {
            echo $hth->search_trow("rate:good", "has a positively-rated review (“rate:bad”, “rate:biased”, etc. also work)");
            echo $hth->search_trow("rate:good:me", "has a positively-rated review by you");
        }

        echo $hth->tgroup("Comments");
        echo $hth->search_trow("has:cmt", "at least one visible reviewer comment (not including authors’ response)");
        echo $hth->search_trow("cmt:>=3", "at least <em>three</em> visible reviewer comments");
        echo $hth->search_trow("has:aucmt", "at least one reviewer comment visible to authors");
        echo $hth->search_trow("cmt:sylvia", "“sylvia” (in name/email) wrote at least one visible comment; can combine with counts, use reviewer tags");
        $rrds = $hth->conf->response_rounds();
        if (count($rrds) > 1) {
            echo $hth->search_trow("has:response", "has an author’s response");
            echo $hth->search_trow("has:{$rrds[1]->name}response", "has {$rrds[1]->name} response");
        } else {
            echo $hth->search_trow("has:response", "has author’s response");
        }
        echo $hth->search_trow("anycmt:>1", "at least two visible comments, possibly <em>including</em> author’s response");

        echo $hth->tgroup("Leads");
        echo $hth->search_trow("lead:fdabek", "“fdabek” (in name/email) is discussion lead");
        echo $hth->search_trow("lead:none", "no assigned discussion lead");
        echo $hth->search_trow("lead:any", "some assigned discussion lead");
        echo $hth->tgroup("Shepherds");
        echo $hth->search_trow("shep:fdabek", "“fdabek” (in name/email) is shepherd (“none” and “any” also work)");
        echo $hth->tgroup("Conflicts");
        echo $hth->search_trow("conflict:me", "you have a conflict with the submission");
        echo $hth->search_trow("conflict:fdabek", "“fdabek” (in name/email) has a conflict with the submission<br><div class=\"hint\">This search is only available to chairs and to PC members who can see the submission’s author list.</div>");
        echo $hth->search_trow("conflict:pc", "some PC member has a conflict with the submission");
        echo $hth->search_trow("conflict:pc>2", "at least three PC members have conflicts with the submission");
        echo $hth->search_trow("reconflict:\"1 2 3\"", "a reviewer of submission 1, 2, or 3 has a conflict with the submission");
        echo $hth->tgroup("Preferences");
        echo $hth->search_trow("pref:3", "you have preference 3");
        echo $hth->search_trow("pref:pc:X", "a PC member’s preference has expertise “X” (expert)");
        echo $hth->search_trow("pref:fdabek>0", "“fdabek” (in name/email) has preference &gt;&nbsp;0<br><div class=\"hint\">Administrators can search preferences by name; PC members can only search preferences for the PC as a whole.</div>");
        echo $hth->tgroup("Status");
        echo $hth->search_trow(["q" => "status:ready", "t" => "all"], "submission is ready for review");
        echo $hth->search_trow(["q" => "status:incomplete", "t" => "all"], "submission is incomplete (neither ready nor withdrawn)");
        echo $hth->search_trow(["q" => "status:withdrawn", "t" => "all"], "submission has been withdrawn");
        echo $hth->search_trow("has:final", "final version uploaded");

        echo $hth->tgroup("Decisions");
        foreach ($hth->conf->decision_set() as $dec) {
            if ($dec->id !== 0) {
                $qdname = strtolower($dec->name);
                if (strpos($qdname, " ") !== false) {
                    $qdname = "\"{$qdname}\"";
                }
                echo $hth->search_trow("dec:{$qdname}", "decision is “" . $dec->name_as(5) . "” (partial matches OK)");
                break;
            }
        }
        echo $hth->search_trow("dec:yes", "one of the accept decisions");
        echo $hth->search_trow("dec:no", "one of the reject decisions");
        echo $hth->search_trow("dec:any", "decision specified");
        echo $hth->search_trow("dec:none", "decision unspecified");

        // find names of review fields to demonstrate syntax
        $scoref = [];
        $textf = [];
        foreach ($hth->conf->review_form()->viewable_fields($hth->user) as $f) {
            if ($f instanceof Score_ReviewField) {
                $scoref[] = $f;
            } else if ($f instanceof Text_ReviewField) {
                $textf[] = $f;
            }
        }
        if (!empty($scoref) || !empty($textf)) {
            echo $hth->tgroup("Review fields");
        }
        if (count($scoref)) {
            $r = $scoref[0];
            echo $hth->search_trow("{$r->abbreviation1()}:{$r->typical_score()}", "at least one completed review has {$r->name_html} score {$r->typical_score()}");
            echo $hth->search_trow("{$r->search_keyword()}:{$r->typical_score()}", "other abbreviations accepted");
            if (count($scoref) > 1) {
                $r2 = $scoref[1];
                echo $hth->search_trow(strtolower($r2->search_keyword()) . ":{$r2->typical_score()}", "other fields accepted (here, {$r2->name_html})");
            }
            if (($range = $r->typical_score_range())) {
                echo $hth->search_trow("{$r->search_keyword()}:{$range[0]}..{$range[1]}", "completed reviews’ {$r->name_html} scores are all in the {$range[0]}–{$range[1]} range<br><div class=\"hint\">(nonempty scores between {$range[0]} and {$range[1]})</div>"),
                    $hth->search_trow("{$r->search_keyword()}:{$range[0]}-{$range[1]}", "completed reviews’ {$r->name_html} scores <em>span</em> the {$range[0]}–{$range[1]} range<br><div class=\"hint\">(at least one {$range[0]}, at least one {$range[1]}, and all nonempty scores between {$range[0]} and {$range[1]})</div>");
            }
            $hint = "";
            if ($r->is_numeric()) {
                $gt_typical = "above {$r->typical_score()}";
                $le_typical = "of {$r->typical_score()} or below";
            } else {
                $s1 = $r->parse_string($r->typical_score());
                if ($hth->conf->opt("smartScoreCompare")) {
                    $s1le = range($s1, 1);
                    $s1gt = range($r->nvalues(), $s1 + 1);
                    $hint = "<br><div class=\"hint\">(scores “better than” {$r->typical_score()} are earlier in the alphabet)</div>";
                } else {
                    $s1le = range($r->nvalues(), $s1);
                    $s1gt = range($s1 - 1, 1);
                }
                $gt_typical = commajoin(array_map([$r, "value_unparse"], $s1gt), " or ");
                $le_typical = commajoin(array_map([$r, "value_unparse"], $s1le), " or ");
            }
            echo $hth->search_trow("{$r->search_keyword()}:>{$r->typical_score()}", "at least one completed review has {$r->name_html} score {$gt_typical}" . $hint);
            echo $hth->search_trow("{$r->search_keyword()}:2<={$r->typical_score()}", "at least two completed reviews have {$r->name_html} score {$le_typical}");
            echo $hth->search_trow("{$r->search_keyword()}:=2<={$r->typical_score()}", "<em>exactly</em> two completed reviews have {$r->name_html} score {$le_typical}");
            if ($roundname) {
                echo $hth->search_trow("{$r->search_keyword()}:{$roundname}>{$r->typical_score()}", "at least one completed review in round " . htmlspecialchars($roundname) . " has {$r->name_html} score {$gt_typical}");
            }
            echo $hth->search_trow("{$r->search_keyword()}:ext>{$r->typical_score()}", "at least one completed external review has {$r->name_html} score {$gt_typical}");
            echo $hth->search_trow("{$r->search_keyword()}:pc:2>{$r->typical_score()}", "at least two completed PC reviews have {$r->name_html} score {$gt_typical}");
            echo $hth->search_trow("{$r->search_keyword()}:sylvia={$r->typical_score()}", "“sylvia” (reviewer name/email) gave {$r->name_html} score {$r->typical_score()}");
        }
        if (count($textf)) {
            $r = $textf[0];
            echo $hth->search_trow($r->abbreviation1() . ":finger", "at least one completed review has “finger” in the $r->name_html field");
            echo $hth->search_trow("{$r->search_keyword()}:finger", "other abbreviations accepted");
            echo $hth->search_trow("{$r->search_keyword()}:any", "at least one completed review has text in the $r->name_html field");
        }

        if (count($scoref)) {
            $r = $scoref[0];
            echo $hth->tgroup($hth->help_link("Formulas", "formulas"));
            echo $hth->search_trow("formula:all({$r->search_keyword()}={$r->typical_score()})",
                "all reviews have $r->name_html score {$r->typical_score()}<br>" .
                "<div class=\"hint\">" . $hth->help_link("Formulas", "formulas") . " can express complex numerical queries across review scores and preferences.</div>");
            echo $hth->search_trow("f:all({$r->search_keyword()}={$r->typical_score()})", "“f” is shorthand for “formula”");
            echo $hth->search_trow("formula:var({$r->search_keyword()})>0.5", "variance in {$r->search_keyword()} is above 0.5");
            echo $hth->search_trow("formula:any({$r->search_keyword()}={$r->typical_score()} && pref<0)", "at least one reviewer had $r->name_html score {$r->typical_score()} and review preference &lt; 0");
        }

        echo $hth->tgroup("Display");
        echo $hth->search_trow("show:tags show:pcconflicts", "show tags and PC conflicts in the results");
        echo $hth->search_trow("hide:title", "hide title in the results");
        if (count($scoref)) {
            $r = $scoref[0];
            echo $hth->search_trow("show:max({$r->search_keyword()})", "show a " . $hth->help_link("formula", "formulas"));
            echo $hth->search_trow("sort:{$r->search_keyword()}", "sort by score");
            echo $hth->search_trow("sort:[{$r->search_keyword()} variance]", "sort by score variance");
        }
        echo $hth->search_trow("sort:-status", "sort by reverse status");
        echo $hth->search_trow("edit:#discuss", "edit the values for tag “#discuss”");
        echo $hth->search_trow("search1 THEN search2", "like “search1 OR search2”, but submissions matching “search1” are grouped together and appear earlier in the sorting order");
        echo $hth->search_trow("1-5 THEN 6-10 show:kanban", "display in kanban format");
        echo $hth->search_trow("search1 HIGHLIGHT search2", "search for “search1”, but <span class=\"taghh highlightmark\">highlight</span> submissions in that list that match “search2” (also try HIGHLIGHT:pink, HIGHLIGHT:green, HIGHLIGHT:blue)");

        echo $hth->end_table();
    }
}
