<?php
// search/st_tag.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class Tag_SearchTerm extends SearchTerm {
    /** @var TagSearchMatcher */
    private $tsm;
    private $tag1;
    private $tag1nz;

    function __construct(TagSearchMatcher $tsm) {
        parent::__construct("tag");
        $this->tsm = $tsm;
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $negated = $sword->kwdef->negated;
        $revsort = $sword->kwdef->sorting && $sword->kwdef->revsort;
        if (str_starts_with($word, "-")) {
            if ($sword->kwdef->sorting) {
                $revsort = !$revsort;
                $word = substr($word, 1);
            } else if (!$negated) {
                $negated = true;
                $word = substr($word, 1);
            }
        }
        if (str_starts_with($word, "#")) {
            $word = substr($word, 1);
        }

        // check value matchers
        $value = new TagSearchMatcher($srch->user);
        if (preg_match('/\A([^#=!<>\x80-\xFF]+)(?:#|=)(-?(?:\.\d+|\d+\.?\d*))(?:\.\.\.?|-|–|—)(-?(?:\.\d+|\d+\.?\d*))\z/', $word, $m)) {
            $tagword = $m[1];
            $value->add_value_matcher(new CountMatcher(">=$m[2]"));
            $value->add_value_matcher(new CountMatcher("<=$m[3]"));
        } else if (preg_match('/\A([^#=!<>\x80-\xFF]+)(#?)([=!<>]=?|≠|≤|≥|)(-?(?:\.\d+|\d+\.?\d*))\z/', $word, $m)
                   && $m[1] !== "any"
                   && $m[1] !== "none"
                   && ($m[2] !== "" || $m[3] !== "")) {
            $tagword = $m[1];
            $value->add_value_matcher(new CountMatcher(($m[3] ? : "=") . $m[4]));
        } else {
            $tagword = $word;
        }

        // match tag body
        $value->add_check_tag($tagword, !$sword->kwdef->sorting);

        // expand automatic tags if requested
        $allterms = [];
        if (true
            && ($dt = $srch->conf->tags())->has_automatic) {
            $nomatch = [];
            foreach ($dt->filter("automatic") as $t) {
                if ($value->test_ignore_value(" {$t->tag}#")
                    && $t->automatic_formula_expression() === "0") {
                    $nomatch[] = " " . preg_quote($t->tag) . "#";
                    if ($value->test_value(0.0)) {
                        $asrch = new PaperSearch($srch->conf->site_contact(), ["q" => $t->automatic_search(), "t" => "all"]);
                        $allterms[] = $asrch->term();
                    }
                }
            }
            if (!empty($nomatch)) {
                $value->set_tag_exclusion_regex(join("|", $nomatch));
            }
        }

        // add value term
        if (!$value->is_empty_after_exclusion()) {
            $allterms[] = $term = new Tag_SearchTerm($value);
            if (!$negated && ($tagpat = $value->tag_patterns())) {
                $term->set_float("tags", $tagpat);
                if ($sword->kwdef->sorting) {
                    $term->set_float("view", ["sort:" . ($revsort ? "-#" : "#") . $tagpat[0]]);
                }
            }
            if (!$negated && $sword->kwdef->is_hash && $value->single_tag()) {
                $term->tag1 = $value->single_tag();
                $term->tag1nz = false;
            }
        }

        // return
        foreach ($value->error_texts() as $e) {
            $srch->warn($e);
        }
        return SearchTerm::combine("or", $allterms)->negate_if($negated);
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        if ($this->tsm->test_empty()) {
            return "true";
        } else {
            $sql = $this->tsm->sqlexpr("PaperTag");
            return 'exists (select * from PaperTag where paperId=Paper.paperId' . ($sql ? " and $sql" : "") . ')';
        }
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        $ok = $this->tsm->test($row->searchable_tags($srch->user));
        if ($ok && $this->tag1 && !$this->tag1nz) {
            $this->tag1nz = $row->tag_value($this->tag1) != 0;
        }
        return $ok;
    }
    function default_sort_column($top, PaperSearch $srch) {
        if ($top && $this->tag1) {
            $dt = $srch->conf->tags()->check(Tagger::base($this->tag1));
            if (($dt && $dt->order_anno) || $this->tag1nz) {
                $xjs = Tag_PaperColumn::expand("#{$this->tag1}", $srch->user, (object) [], ["#{$this->tag1}", "#", $this->tag1]);
                assert(count($xjs) === 1 && $xjs[0]->callback === "+Tag_PaperColumn");
                return PaperColumn::make($srch->conf, $xjs[0], $dt && $dt->votish ? ["reverse"] : []);
            }
        }
        return null;
    }
    function debug_json() {
        return ["type" => $this->type, "tag_regex" => $this->tsm->regex()];
    }
}
