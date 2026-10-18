{{--
    Die Vorlage fuer die Benachrichtigungs-Mail — einzeln wie gesammelt.

    Bewusst Markdown-Mail und kein eigenes Layout: Jedes der vier Produkte
    hat seine Mail-Gestaltung schon, und die Komponenten von Laravel nehmen
    sie auf. Eine eigene Gestaltung hier saehe in jedem Produkt fremd aus.
--}}
<x-mail::message>
# Hallo {{ $name }},

@if (count($zeilen) === 1)
es gibt eine neue Benachrichtigung aus {{ $system }}:
@else
seit der letzten Mail sind {{ count($zeilen) }} Benachrichtigungen aus {{ $system }} aufgelaufen:
@endif

@foreach ($zeilen as $zeile)
**{{ $zeile['title'] }}**
@if (! empty($zeile['message']))
{{ $zeile['message'] }}
@endif
@if (! empty($zeile['link']))
[Ansehen]({{ $zeile['link'] }})
@endif

@if (! $loop->last)
---
@endif
@endforeach

@if ($posteingang)
<x-mail::button :url="$posteingang">
Posteingang öffnen
</x-mail::button>
@endif

{{-- Der Hinweis gehoert in JEDE dieser Mails: Wer sie nicht will, soll
     nicht suchen muessen, wo man sie abstellt. --}}
Diese Mail kommt, weil du für diese Benachrichtigungsart „E-Mail" eingeschaltet hast.
@if ($posteingang)
Ändern lässt sich das unter *Einstellungen → Benachrichtigungen*.
@endif
</x-mail::message>
