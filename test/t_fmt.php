<?php
// t_fmt.php -- HotCRP tests
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Fmt_Tester {
    function test_1() {
        $ms = new Fmt;
        $ms->add("Hello", "Bonjour");
        $ms->add(["%d friend", "%d amis", ["$1 ≠ 1"]]);
        $ms->add("%d friend", "%d ami");
        $ms->add("ax", "a");
        $ms->add("ax", "b");
        $ms->add("bx", "a", 2);
        $ms->add("bx", "b");
        $ms->add(["fart", "fart example A", ["{0}=bob"]]);
        $ms->add(["fart", "fart example B", ["{0}^=bob"]]);
        $ms->add(["fart", "fart example C"]);
        $ms->add(["id" => "fox-saying", "itext" => "What the fox said"]);
        $ms->add(["id" => "fox-saying", "itext" => "What the {fox} said", "require" => ["{fox}"]]);
        $ms->add(["itext" => "butt", "otext" => "%1\$s", "context" => "test103", "template" => true]);
        $ms->add_override("test103", "%BUTT% %% %s %BU%%MAN%%BUTT%");
        xassert_eqq($ms->_("Hello"), "Bonjour");
        xassert_eqq($ms->_("%d friend", 1), "1 ami");
        xassert_eqq($ms->_("%d friend", 0), "0 amis");
        xassert_eqq($ms->_("%d friend", 2), "2 amis");
        xassert_eqq($ms->_("%1[foo]\$s friend", ["foo" => 3]), "3 friend");
        xassert_eqq($ms->_("ax"), "b");
        xassert_eqq($ms->_("bx"), "a");
        xassert_eqq($ms->_("%xOOB%x friend", 10, 11), "aOOBb friend");
        xassert_eqq($ms->_("%xOOB%x%% friend", 10, 11), "aOOBb% friend");
        xassert_eqq($ms->_("fart"), "fart example C");
        xassert_eqq($ms->_("fart", "bobby"), "fart example B");
        xassert_eqq($ms->_("fart", "bob"), "fart example A");
        xassert_eqq($ms->_id("fox-saying", ""), "What the fox said");
        xassert_eqq($ms->_id("fox-saying", "", new FmtArg("fox", "Animal")), "What the Animal said");
        xassert_eqq($ms->_id("test103", "", "Ass"), "Ass %% %s %BU%%MAN%Ass");

        $ms->add(["itext" => "butt", "otext" => "normal butt"]);
        $ms->add(["itext" => "butt", "otext" => "fat butt", "require" => ["$1[fat]"]]);
        $ms->add(["itext" => "butt", "otext" => "two butts", "require" => ["$1[count]>1"], "priority" => 1]);
        $ms->add(["itext" => "butt", "otext" => "three butts", "require" => ["$1[count]>2"], "priority" => 2]);
        xassert_eqq($ms->_("butt"), "normal butt");
        xassert_eqq($ms->_("butt", []), "normal butt");
        xassert_eqq($ms->_("butt", ["thin" => true]), "normal butt");
        xassert_eqq($ms->_("butt", ["fat" => true]), "fat butt");
        xassert_eqq($ms->_("butt", ["fat" => false]), "normal butt");
        xassert_eqq($ms->_("butt", ["fat" => true, "count" => 2]), "two butts");
        xassert_eqq($ms->_("butt", ["fat" => false, "count" => 2]), "two butts");
        xassert_eqq($ms->_("butt", ["fat" => true, "count" => 3]), "three butts");
        xassert_eqq($ms->_("butt", ["fat" => false, "count" => 2.1]), "three butts");
    }

    function test_contexts() {
        $ms = new Fmt;
        $ms->add("Hello", "Hello");
        $ms->add(["hello", "Hello", "Hello1"]);
        $ms->add(["hello/yes", "Hello", "Hello2"]);
        $ms->add(["hellop", "Hello", "Hellop", 2]);
        xassert_eqq($ms->_c(null, "Hello"), "Hello");
        xassert_eqq($ms->_c("hello", "Hello"), "Hello1");
        xassert_eqq($ms->_c("hello/no", "Hello"), "Hello1");
        xassert_eqq($ms->_c("hello/yes", "Hello"), "Hello2");
        xassert_eqq($ms->_c("hello/yes/whatever", "Hello"), "Hello2");
        xassert_eqq($ms->_c("hello/ye", "Hello"), "Hello1");
        xassert_eqq($ms->_c("hello/yesp", "Hello"), "Hello1");
    }
}
