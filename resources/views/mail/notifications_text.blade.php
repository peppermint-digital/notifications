{{--
    Der Plain-Text-Teil. Pflicht laut Standard, und nicht aus Nostalgie:

    Eine Mail ohne Text-Alternative bekommt bei den meisten Filtern
    Spam-Punkte. Bei einer Mail, die regelmaessig an dieselben Leute geht,
    entscheidet das ueber Posteingang oder Werbeordner.
--}}
Hallo {{ $name }},

@if (count($zeilen) === 1)
es gibt eine neue Benachrichtigung:
@else
seit der letzten Mail sind {{ count($zeilen) }} Benachrichtigungen aufgelaufen:
@endif

@foreach ($zeilen as $zeile)
* {{ $zeile['title'] }}
@if (! empty($zeile['message']))
  {{ $zeile['message'] }}
@endif
@if (! empty($zeile['link']))
  {{ $zeile['link'] }}
@endif

@endforeach
@if ($posteingang)
Posteingang: {{ $posteingang }}
@endif

--
Diese Mail kommt, weil du für diese Benachrichtigungsart "E-Mail" eingeschaltet hast.
@if ($einstellungen)
Ändern: {{ $einstellungen }}
@endif
