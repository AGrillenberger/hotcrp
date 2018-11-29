<?php
// src/settings/s_reviewvisibility.php -- HotCRP settings > decisions page
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class DecisionVisibility_SettingParser extends SettingParser {
    static function render(SettingValues $sv) {
        $extrev_view = $sv->curv("extrev_view");
        $Rtext = $extrev_view ? "Reviewers" : "PC reviewers";
        $rtext = $extrev_view ? "reviewers" : "PC reviewers";
        $sv->echo_radio_table("seedec", [Conf::SEEDEC_ADMIN => "Only administrators",
                Conf::SEEDEC_NCREV => "$Rtext and non-conflicted PC members",
                Conf::SEEDEC_REV => "$Rtext and <em>all</em> PC members",
                Conf::SEEDEC_ALL => "<b>Authors</b>, $rtext, and all PC members (and reviewers can see accepted submissions’ author lists)"],
            'Who can see <strong>decisions</strong> (accept/reject)?');

        echo '<div class="settings-g">';
        $sv->echo_checkbox("shepherd_hide", "Hide shepherd names from authors");
        echo "</div>\n";
    }

    static function crosscheck(SettingValues $sv) {
        global $Now;

        if ($sv->has_interest("seedec")
            && $sv->newv("seedec") == Conf::SEEDEC_ALL
            && $sv->newv("au_seerev") == Conf::AUSEEREV_NO)
            $sv->warning_at(null, "Authors can see decisions, but not reviews. This is sometimes unintentional.");

        if (($sv->has_interest("seedec") || $sv->has_interest("sub_sub"))
            && $sv->newv("sub_open")
            && $sv->newv("sub_sub") > $Now
            && $sv->newv("seedec") != Conf::SEEDEC_ALL
            && $sv->conf->fetch_value("select paperId from Paper where outcome<0 limit 1") > 0)
            $sv->warning_at(null, "Updates will not be allowed for rejected submissions. This exposes decision information that would otherwise be hidden from authors.");
    }
}
