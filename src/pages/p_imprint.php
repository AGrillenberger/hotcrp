<?php
// pages/p_home.php -- HotCRP home page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Imprint_Page {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $user;
    /** @var int */
    private $_nh2 = 0;
    /** @var bool */
    private $_has_sidebar = false;
    private $_in_reviews;
    /** @var ?list<ReviewField> */
    private $_rfs;
    /** @var int */
    private $_r_num_submitted = 0;
    /** @var int */
    private $_r_num_needs_submit = 0;
    /** @var list<int> */
    private $_r_unsubmitted_rounds;
    /** @var list<int|float> */
    private $_rf_means;
    private $_tokens_done;

    function __construct(Contact $user) {
        $this->conf = $user->conf;
        $this->user = $user;
    }

    function print_head(Contact $user, Qrequest $qreq, $gx) {
        if ($user->is_empty()) {
            $qreq->print_header("Sign in", "home");
        } else {
            $qreq->print_header("Home", "home");
        }
        if ($qreq->signedout && $user->is_empty()) {
            $user->conf->success_msg("<0>You have been signed out of the site");
        }
        $gx->push_print_cleanup("__footer");
        echo '<noscript><div class="msg msg-error"><strong>This site requires JavaScript.</strong> Your browser does not support JavaScript.<br><a href="https://github.com/kohler/hotcrp/">Report bad compatibility problems</a></div></noscript>', "\n";
        if ($user->privChair) {
            echo '<div id="msg-clock-drift" class="homegrp hidden"></div>';
        }
    }

    function print_content(Contact $user, Qrequest $qreq, $gx) {
        echo '<main class="imprint-content">';
        ob_start();
        $gx->print_group("imprint/sidebar");
        if (($t = ob_get_clean()) !== "") {
            echo '<nav class="home-sidebar">', $t, '</nav>';
            $this->_has_sidebar = true;
        }
        echo '<div class="imprint-main">';
        echo <<<ENDHTML
            <h2>Current WiPSCE chair / person responsible for content</h2>
            <p>
<bold>Prof. Dr. Tilman Michaeli</bold>
<br>
" Computing Education Research Group Munich"
<br>
" School of Social Sciences and Technology"
<br>
" Technical University of Munich"
<br>
" Arcisstraße 21"
<br>
" 80333 Munich, Germany "
</p>
<p>
" Office phone: "
<a href="tel:+49 89 289 - 24252">+49 89 289 - 24252</a>
<br>
" Email: tilman.michaeli@tum.de "
</p>
            ENDHTML;
        echo "</div></main>\n";
    }
}
