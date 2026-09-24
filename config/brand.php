<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Name and slogan
    |--------------------------------------------------------------------------
    |
    | Kept in one place because these strings end up in a dozen of them: the
    | page title, the header, the installed app's name on a phone home screen,
    | the weekly report email, the sign-in screen. A rename that has to be done
    | by hand in a dozen files is a rename that gets done in nine.
    |
    | "پیگیر" is a word people already use for a person who chases things up,
    | which is the whole product in one word. The slogan states the outcome a
    | buyer wants rather than the feature that delivers it — nobody buys
    | software, they buy the result.
    |
    */

    'name' => env('BRAND_NAME', 'پیگیر'),

    'slogan' => env('BRAND_SLOGAN', 'هیچ کاری زمین نمی‌ماند'),

    /*
    | The sentence for "خب این چیکار می‌کنه؟". One line, says what it is and
    | who it is for, and leads with the part no competitor has.
    */

    'positioning' => 'کارهایی که می‌سپارید را خودش پیگیری می‌کند — با پیامک، '
        .'حتی از کسانی که هرگز وارد سامانه نمی‌شوند.',

];
