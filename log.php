<?php
// log.php -- HotCRP action log
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
if (!$Me->is_manager())
    $Me->escape();

list($DEFAULT_COUNT, $MAX_COUNT) = array(50, 200);

$page = req("page");
if ($page === "earliest")
    $page = false;
else {
    $page = cvtint($page, -1);
    if ($page <= 0)
        $page = 1;
}

if (($count = cvtint(@$_REQUEST["n"], -1)) <= 0)
    $count = $DEFAULT_COUNT;
$count = min($count, $MAX_COUNT);

$nlinks = 4;

$Conf->header("Log", "actionlog", actionBar());

$wheres = array();
$Eclass["q"] = $Eclass["p"] = $Eclass["acct"] = $Eclass["n"] = $Eclass["date"] = "";

$_REQUEST["q"] = trim(defval($_REQUEST, "q", ""));
$_REQUEST["p"] = trim(defval($_REQUEST, "p", ""));
$_REQUEST["acct"] = trim(defval($_REQUEST, "acct", ""));
$_REQUEST["n"] = trim(defval($_REQUEST, "n", "$DEFAULT_COUNT"));
$_REQUEST["date"] = trim(defval($_REQUEST, "date", "now"));

$include_pids = null;
if ($_REQUEST["p"]) {
    $Search = new PaperSearch($Me, array("t" => "all", "q" => $_REQUEST["p"],
                                         "allow_deleted" => true));
    if (count($Search->warnings))
        $Conf->warnMsg(join("<br />\n", $Search->warnings));
    $include_pids = $Search->paperList();
    if (!empty($include_pids)) {
        $where = array();
        foreach ($include_pids as $p) {
            $where[] = "paperId=$p";
            $where[] = "action like '%(papers% $p,%'";
            $where[] = "action like '%(papers% $p)%'";
        }
        $wheres[] = "(" . join(" or ", $where) . ")";
        $include_pids = array_flip($include_pids);
    } else {
        if (!count($Search->warnings))
            $Conf->warnMsg("No papers match that search.");
        $wheres[] = "false";
    }
}

if ($_REQUEST["acct"]) {
    $ids = array();
    $accts = $_REQUEST["acct"];
    while (($word = PaperSearch::pop_word($accts, $Conf))) {
        $flags = ContactSearch::F_TAG | ContactSearch::F_USER | ContactSearch::F_ALLOW_DELETED;
        if (substr($word, 0, 1) === "\"") {
            $flags |= ContactSearch::F_QUOTED;
            $word = preg_replace(',(?:\A"|"\z),', "", $word);
        }
        $Search = new ContactSearch($flags, $word, $Me);
        foreach ($Search->ids as $id)
            $ids[$id] = $id;
    }
    $where = array();
    if (count($ids)) {
        $result = $Conf->qe("select contactId, email from ContactInfo where contactId?a union select contactId, email from DeletedContactInfo where contactId?a", $ids, $ids);
        while (($row = edb_row($result))) {
            $where[] = "contactId=$row[0]";
            $where[] = "destContactId=$row[0]";
            $where[] = "action like " . Dbl::utf8ci("'% " . sqlq_for_like($row[1]) . "%'");
        }
    }
    if (count($where))
        $wheres[] = "(" . join(" or ", $where) . ")";
    else {
        $Conf->infoMsg("No accounts match “" . htmlspecialchars($_REQUEST["acct"]) . "”.");
        $wheres[] = "false";
    }
}

if (($str = $_REQUEST["q"])) {
    $where = array();
    while (($str = ltrim($str)) != "") {
        preg_match('/^("[^"]+"?|[^"\s]+)/s', $str, $m);
        $str = substr($str, strlen($m[0]));
        $where[] = "action like " . Dbl::utf8ci("'%" . sqlq_for_like($m[0]) . "%'");
    }
    $wheres[] = "(" . join(" or ", $where) . ")";
}

if (($count = cvtint(@$_REQUEST["n"])) <= 0) {
    Conf::msg_error("\"Show <i>n</i> records\" requires a number greater than 0.");
    $Eclass["n"] = " error";
    $count = $DEFAULT_COUNT;
}

$firstDate = false;
if ($_REQUEST["date"] == "")
    $_REQUEST["date"] = "now";
if ($_REQUEST["date"] != "now" && isset($_REQUEST["search"])) {
    $firstDate = $Conf->parse_time($_REQUEST["date"]);
    if ($firstDate === false) {
        Conf::msg_error("“" . htmlspecialchars($_REQUEST["date"]) . "” is not a valid date.");
        $Eclass["date"] = " error";
    } else if ($firstDate)
        $wheres[] = "time<=from_unixtime($firstDate)";
}

class LogRowGenerator {
    private $conf;
    private $wheres;
    private $page_size;
    private $lower_offset_bound = 0;
    private $upper_offset_bound = INF;
    private $rows_offset;
    private $rows_limit;
    private $rows;
    private $filter;
    private $page_to_offset;

    function __construct(Conf $conf, $wheres, $page_size) {
        $this->conf = $conf;
        $this->wheres = $wheres;
        $this->page_size = $page_size;
    }

    function has_filter() {
        return !!$this->filter;
    }

    function set_filter($filter) {
        $this->filter = $filter;
        $this->rows = null;
        $this->lower_offset_bound = 0;
        $this->upper_offset_bound = INF;
        $this->page_to_offset = [];
    }

    private function load_rows($pageno, $limit) {
        $q = "select logId, unix_timestamp(time) timestamp, ipaddr, contactId, destContactId, action, paperId from ActionLog";
        if (!empty($this->wheres))
            $q .= " where " . join(" and ", $this->wheres);
        $offset = ($pageno - 1) * $this->page_size;
        $db_offset = $offset;
        if ($this->filter && $db_offset !== 0) {
            if (!isset($this->page_to_offset[$pageno]))
                $this->load_rows(max($pageno - 4, 1), 4 * $this->page_size + 1);
            $db_offset = $this->page_to_offset[$pageno];
        }

        $this->rows = [];
        $this->rows_offset = $offset;
        $this->rows_limit = $limit;
        $n = 0;
        while ($n < $limit) {
            $result = $this->conf->qe_raw($q . " order by logId desc limit $db_offset,$limit");
            $first_db_offset = $db_offset;
            while ($result && ($row = $result->fetch_object())) {
                ++$db_offset;
                if (!$this->filter || call_user_func($this->filter, $row)) {
                    $this->rows[] = $row;
                    ++$n;
                    if ($this->filter && $n % $this->page_size === 0)
                        $this->page_to_offset[$pageno + ($n / $this->page_size)] = $db_offset;
                }
            }
            Dbl::free($result);

            if ($first_db_offset + $limit !== $db_offset)
                break;
        }

        if ($n > 0)
            $this->lower_offset_bound = max($this->lower_offset_bound, $this->rows_offset + $n);
        if ($n < $limit)
            $this->upper_offset_bound = min($this->upper_offset_bound, $this->rows_offset + $n);
    }

    function has_page($pageno, $load_npages = null) {
        global $nlinks;
        assert(is_int($pageno) && $pageno >= 1);
        $offset = ($pageno - 1) * $this->page_size;
        if ($offset >= $this->lower_offset_bound && $offset < $this->upper_offset_bound) {
            $limit = $load_npages ? $load_npages * $this->page_size : ($nlinks + 1) * $this->page_size + 30;
            $this->load_rows($pageno, $limit);
        }
        return $offset < $this->lower_offset_bound;
    }

    function page_rows($pageno) {
        assert(is_int($pageno) && $pageno >= 1);
        if (!$this->has_page($pageno))
            return [];
        $offset = ($pageno - 1) * $this->page_size;
        if ($offset < $this->rows_offset
            || $offset + $this->page_size > $this->rows_offset + $this->rows_limit)
            $this->load_rows($pageno, $this->page_size);
        return array_slice($this->rows, $offset - $this->rows_offset, $this->page_size);
    }

    static function row_pid_filter($row, $pidset, $want_in, $include_pids, $no_papers_result) {
        if (preg_match('/\A(.*) \(papers ([\d, ]+)\)?\z/', $row->action, $m)) {
            preg_match_all('/\d+/', $m[2], $mm);
            $pids = [];
            foreach ($mm[0] as $pid)
                if (isset($pidset[$pid]) === $want_in)
                    $pids[] = $pid;
            if (empty($pids))
                return false;
            if ($include_pids) {
                $ok = false;
                foreach ($pids as $pid)
                    if (isset($include_pids[$pid]))
                        $ok = true;
                if (!$ok)
                    return false;
            }
            if (count($pids) === 1) {
                $row->action = $m[1];
                $row->paperId = $pids[0];
            } else
                $row->action = $m[1] . " (papers " . join(", ", $pids) . ")";
            return true;
        } else
            return $no_papers_result;
    }
}

function searchbar(LogRowGenerator $lrg, $page, $count) {
    global $Conf, $Me, $Eclass, $nlinks;

    echo Ht::form_div(hoturl("log"), array("method" => "get")), "<table id='searchform'><tr>
  <td class='lxcaption", $Eclass['q'], "'>With <b>any</b> of the words</td>
  <td class='lentry", $Eclass['q'], "'>", Ht::entry("q", req_s("q"), ["size" => 40]),
        "<span class=\"sep\"></span></td>
  <td rowspan='3'>", Ht::submit("search", "Search"), "</td>
</tr><tr>
  <td class='lxcaption", $Eclass['p'], "'>Concerning paper(s)</td>
  <td class='lentry", $Eclass['p'], "'>", Ht::entry("p", req_s("p"), ["size" => 40]), "</td>
</tr><tr>
  <td class='lxcaption", $Eclass['acct'], "'>Concerning account(s)</td>
  <td class='lentry", $Eclass["acct"], "'>", Ht::entry("acct", req_s("acct"), ["size" => 40]), "</td>
</tr><tr>
  <td class='lxcaption", $Eclass['n'], "'>Show</td>
  <td class='lentry", $Eclass['n'], "'>", Ht::entry("n", req_s("n"), ["size" => 4]), " &nbsp;records at a time</td>
</tr><tr>
  <td class='lxcaption", $Eclass['date'], "'>Starting at</td>
  <td class='lentry", $Eclass['date'], "'>", Ht::entry("date", req_s("date"), ["size" => 40]), "</td>
</tr>";
    if ($Me->privChair && (req("forceShow") || $lrg->has_filter()))
        echo "<tr>
  <td class=\"lentry\" colspan=\"2\">", Ht::checkbox("forceShow", 1, !!req("forceShow")),
        "&nbsp;", Ht::label("Include results for conflict papers administered by others"),
        "</td>\n</tr>";
    echo "</table></div></form>";

    if ($page > 1 || $lrg->has_page(2)) {
        $urls = array();
        foreach (array("q", "p", "acct", "n") as $x)
            if ($_REQUEST[$x])
                $urls[] = "$x=" . urlencode($_REQUEST[$x]);
        $url = hoturl("log", join("&amp;", $urls));
        echo "<table class='lognav'><tr><td><div class='lognavdr'>";
        if ($page > 1)
            echo "<a href='$url&amp;page=1'><strong>Newest</strong></a> &nbsp;|&nbsp;&nbsp;";
        echo "</div></td><td><div class='lognavxr'>";
        if ($page > 1)
            echo "<a href='$url&amp;page=", ($page - 1), "'><strong>", Ht::img("_.gif", "<-", array("class" => "prev")), " Newer</strong></a>";
        echo "</div></td><td><div class='lognavdr'>";
        if ($page - $nlinks > 1)
            echo "&nbsp;...";
        for ($p = max($page - $nlinks, 1); $p < $page; ++$p)
            echo "&nbsp;<a href='$url&amp;page=", $p, "'>", $p, "</a>";
        echo "</div></td><td><div><strong class='thispage'>&nbsp;", $page, "&nbsp;</strong></div></td><td><div class='lognavd'>";
        for ($p = $page + 1; $p <= $page + $nlinks && $lrg->has_page($p); ++$p)
            echo "<a href='$url&amp;page=", $p, "'>", $p, "</a>&nbsp;";
        if ($lrg->has_page($page + $nlinks + 1))
            echo "...&nbsp;";
        echo "</div></td><td><div class='lognavx'>";
        if ($lrg->has_page($page + 1))
            echo "<a href='$url&amp;page=", ($page + 1), "'><strong>Older ", Ht::img("_.gif", "->", array("class" => "next")), "</strong></a>";
        echo "</div></td><td><div class='lognavd'>";
        if ($lrg->has_page($page + $nlinks + 1))
            echo "&nbsp;&nbsp;|&nbsp; <a href='$url&amp;page=earliest'><strong>Oldest</strong></a>";
        echo "</div></td></tr></table>";
    }
    echo "<div class='g'></div>\n";
}

$lrg = new LogRowGenerator($Conf, $wheres, $count);

if (!$Me->privChair) {
    $result = $Conf->paper_result($Me, $Conf->check_any_admin_tracks($Me) ? [] : ["myManaged" => true]);
    $good_pids = [];
    foreach (PaperInfo::fetch_all($result, $Me) as $prow)
        if ($Me->allow_administer($prow))
            $good_pids[$prow->paperId] = true;
    $lrg->set_filter(function ($row) use ($good_pids, $include_pids, $Me) {
        if ($row->contactId === $Me->contactId)
            return true;
        else if ($row->paperId)
            return isset($good_pids[$row->paperId]);
        else
            return LogRowGenerator::row_pid_filter($row, $good_pids, true, $include_pids, false);
    });
} else if ($Conf->has_any_manager() && !req("forceShow")) {
    $result = $Conf->paper_result($Me, ["myConflicts" => true]);
    $bad_pids = [];
    foreach (PaperInfo::fetch_all($result, $Me) as $prow)
        if (!$Me->allow_administer($prow))
            $bad_pids[$prow->paperId] = true;
    if (!empty($bad_pids))
        $lrg->set_filter(function ($row) use ($bad_pids, $include_pids, $Me) {
            if ($row->contactId === $Me->contactId)
                return true;
            else if ($row->paperId)
                return !isset($bad_pids[$row->paperId]);
            else
                return LogRowGenerator::row_pid_filter($row, $bad_pids, false, $include_pids, true);
        });
}

if ($page === false) { // handle `earliest`
    $page = 1;
    while ($lrg->has_page($page + 1, ceil(2000 / $count)))
        ++$page;
}

$visible_rows = $lrg->page_rows($page);
$unknown_cids = [];
$users = $Conf->pc_members_and_admins();
foreach ($visible_rows as $row) {
    if ($row->contactId && !isset($users[$row->contactId]))
        $unknown_cids[$row->contactId] = true;
    if ($row->destContactId && !isset($users[$row->destContactId]))
        $unknown_cids[$row->destContactId] = true;
}

// load unknown users
if (!empty($unknown_cids)) {
    $result = $Conf->qe("select contactId, firstName, lastName, email, roles from ContactInfo where contactId?a", array_keys($unknown_cids));
    while (($user = Contact::fetch($result, $Conf))) {
        $users[$user->contactId] = $user;
        unset($unknown_cids[$user->contactId]);
    }
    Dbl::free($result);
    if (!empty($unknown_cids)) {
        foreach ($unknown_cids as $cid => $x) {
            $user = $users[$cid] = new Contact(["contactId" => $cid, "disabled" => true]);
            $user->disabled = "deleted";
        }
        $result = $Conf->qe("select contactId, firstName, lastName, email, 1 disabled from DeletedContactInfo where contactId?a", array_keys($unknown_cids));
        while (($user = Contact::fetch($result, $Conf))) {
            $users[$user->contactId] = $user;
            $user->disabled = "deleted";
        }
        Dbl::free($result);
    }
}

// render rows
function render_user(Contact $user = null) {
    global $Me;
    if (!$user)
        return "";
    else if (!$user->email && $user->disabled === "deleted")
        return '<del>' . $user->contactId . '</del>';
    else {
        $t = $Me->reviewer_html_for($user);
        if ($user->disabled === "deleted")
            $t = "<del>" . $t . " &lt;" . htmlspecialchars($user->email) . "&gt;</del>";
        else {
            $t = '<a href="' . hoturl("profile", "u=" . urlencode($user->email)) . '">' . $t . '</a>';
            if (!isset($user->roles) || !($user->roles & Contact::ROLE_PCLIKE))
                $t .= ' &lt;' . htmlspecialchars($user->email) . '&gt;';
            if (isset($user->roles) && ($rolet = $user->role_html()))
                $t .= " $rolet";
        }
        return $t;
    }
}

$trs = [];
$has_dest_user = false;
foreach ($visible_rows as $row) {
    $act = $row->action;

    $t = ['<td class="pl pl_time">' . $Conf->unparse_time_short($row->timestamp) . '</td>'];
    $t[] = '<td class="pl pl_ip">' . htmlspecialchars($row->ipaddr) . '</td>';

    $user = $row->contactId ? get($users, $row->contactId) : null;
    $dest_user = $row->destContactId ? get($users, $row->destContactId) : null;
    if (!$user && $dest_user)
        $user = $dest_user;

    $t[] = '<td class="pl pl_name">' . render_user($user) . '</td>';
    if ($dest_user && $user !== $dest_user) {
        $t[] = '<td class="pl pl_name">' . render_user($dest_user) . '</td>';
        $has_dest_user = true;
    } else
        $t[] = '<td></td>';

    // XXX users that aren't in contactId slot
    // if (preg_match(',\A(.*)<([^>]*@[^>]*)>\s*(.*)\z,', $act, $m)) {
    //     $t .= htmlspecialchars($m[2]);
    //     $act = $m[1] . $m[3];
    // } else
    //     $t .= "[None]";

    if (preg_match('/\AReview (\d+)(.*)\z/s', $act, $m)) {
        $at = "<a href=\"" . hoturl("review", "r=$m[1]") . "\">Review " . $m[1] . "</a>";
        $act = $m[2];
    } else if (preg_match('/\AComment (\d+)(.*)\z/s', $act, $m)) {
        $at = "<a href=\"" . hoturl("paper", "p=$row->paperId") . "\">Comment " . $m[1] . "</a>";
        $act = $m[2];
    } else if (preg_match('/\A(Sending|Sent|Account was sent) mail #(\d+)(.*)\z/s', $act, $m)) {
        $at = $m[1] . " <a href=\"" . hoturl("mail", "fromlog=$m[2]") . "\">mail #$m[2]</a>";
        $act = $m[3];
    } else
        $at = "";
    if (preg_match('/\A(.*) \(papers ([\d, ]+)\)?\z/', $act, $m)) {
        $at .= htmlspecialchars($m[1])
            . " (<a href=\"" . hoturl("search", "t=all&amp;q=" . preg_replace('/[\s,]+/', "+", $m[2]))
            . "\">papers</a> "
            . preg_replace('/(\d+)/', "<a href=\"" . hoturl("paper", "p=\$1") . "\">\$1</a>", $m[2])
            . ")";
    } else
        $at .= htmlspecialchars($act);
    if ($row->paperId)
        $at .= " (paper <a href=\"" . hoturl("paper", "p=" . urlencode($row->paperId)) . "\">" . htmlspecialchars($row->paperId) . "</a>)";
    $t[] = '<td class="pl pl_act">' . $at . '</td>';
    $trs[] = '    <tr class="k' . (count($trs) % 2) . '">' . join("", $t) . "</tr>\n";
}

searchbar($lrg, $page, $count);
if (!empty($trs)) {
    echo '<table class="pltable pltable_full">
  <thead><tr class="pl_headrow"><th class="pll pl_time">Time</th><th class="pll pl_ip">IP</th><th class="pll pl_name">User</th>';
    if ($has_dest_user)
        echo '<th class="pll pl_name">Affected user</th>';
    else
        echo '<th></th>';
    echo '<th class="pll pl_act">Action</th></tr></thead>',
        "\n  <tbody class=\"pltable\">\n",
        join("", $trs),
        "  </tbody>\n</table>\n";
} else
    echo "No records\n";

$Conf->footer();
