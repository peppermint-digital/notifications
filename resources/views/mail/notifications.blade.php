{{--
    Die Benachrichtigungs-Mail — einzeln wie gesammelt.

    ## Warum das hier Tabellen und Inline-Styles sind und kein hübsches HTML

    Verbindlicher Standard, Wiki „E-Mail-Template-Standard: bulletproof
    HTML-Mails". Die harten Gründe:

    - **Outlook Desktop** rendert mit der Word-Engine: kein `border-radius`,
      kein `box-shadow`, kein Flexbox, ignoriert `max-width` und ignoriert
      Padding auf einem `<a>`. Ein gepaddeter Link-Button ist dort ein
      nackter Text.
    - **web.de und GMX** strippen den kompletten `<style>`-Block. Alles, was
      als Klasse definiert ist, ist dort wirkungslos — deshalb steht jeder
      Stil inline und jede Farbfläche zusätzlich als `bgcolor`-Attribut.
    - **Schriftstapel wie `-apple-system`** sehen auf Mac anders aus als auf
      Windows. Arial/Helvetica ist überall dasselbe.

    Die erste Fassung dieser Mail benutzte Laravels Markdown-Vorlage. Die ist
    nicht falsch, aber sie bringt `border-radius`, einen OS-abhängigen
    Schriftstapel und keinen Plain-Text-Teil mit — und der fehlende
    Plain-Text-Teil kostet Spam-Punkte.
--}}
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
{{-- Hell erzwingen. Der manuelle Dunkel-Schalter mancher Clients bleibt
     unkontrollierbar — das ist bewusst hingenommen. --}}
<meta name="color-scheme" content="light only" />
<meta name="supported-color-schemes" content="light only" />
<title>{{ $betreff }}</title>
<!--[if mso]>
<xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml>
<![endif]-->
</head>
<body style="margin:0; padding:0; background-color:#f4f4f5;" bgcolor="#f4f4f5">

{{-- Die Vorschauzeile im Posteingang. Ohne sie zeigt der Client den Anfang
     des sichtbaren Textes, also die Anrede — in jeder Mail dieselbe. --}}
<div style="display:none; max-height:0; overflow:hidden; mso-hide:all; font-size:1px; line-height:1px; color:#f4f4f5;">
    {{ $vorschau }}
    &#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;
</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
       style="border-collapse:collapse; mso-table-lspace:0pt; mso-table-rspace:0pt; background-color:#f4f4f5;"
       bgcolor="#f4f4f5">
<tr>
<td align="center" style="padding:24px 12px;">

    <!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
           style="border-collapse:collapse; mso-table-lspace:0pt; mso-table-rspace:0pt; max-width:600px; background-color:#ffffff; border:1px solid #e5e7eb;"
           bgcolor="#ffffff">

        {{-- Kopf: Flaeche per bgcolor UND inline, damit sie das Strippen
             ueberlebt. Der Text steht hell darauf; faellt die Flaeche doch
             einmal aus, bleibt er auf Weiss unlesbar — deshalb ist der
             Kopf bewusst dunkelgrau und nicht bunt. --}}
        <tr>
            <td align="left" bgcolor="#1f2937"
                style="padding:20px 24px; background-color:#1f2937; font-family:Arial,Helvetica,sans-serif; font-size:18px; font-weight:bold; color:#ffffff;">
                {{ $system }}
            </td>
        </tr>

        <tr>
            <td style="padding:24px; font-family:Arial,Helvetica,sans-serif; font-size:15px; line-height:22px; color:#111827;">
                Hallo {{ $name }},
            </td>
        </tr>

        <tr>
            <td style="padding:0 24px 16px 24px; font-family:Arial,Helvetica,sans-serif; font-size:15px; line-height:22px; color:#374151;">
                @if (count($zeilen) === 1)
                    es gibt eine neue Benachrichtigung:
                @else
                    seit der letzten Mail sind {{ count($zeilen) }} Benachrichtigungen aufgelaufen:
                @endif
            </td>
        </tr>

        @foreach ($zeilen as $zeile)
        <tr>
            <td style="padding:0 24px 12px 24px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                       style="border-collapse:collapse; mso-table-lspace:0pt; mso-table-rspace:0pt; border:1px solid #e5e7eb;">
                    <tr>
                        <td style="padding:14px 16px; font-family:Arial,Helvetica,sans-serif;">
                            <div style="font-size:15px; font-weight:bold; color:#111827;">{{ $zeile['title'] }}</div>
                            @if (! empty($zeile['message']))
                            <div style="margin-top:4px; font-size:14px; line-height:20px; color:#4b5563;">{{ $zeile['message'] }}</div>
                            @endif
                            @if (! empty($zeile['link']))
                            <div style="margin-top:8px; font-size:14px;">
                                <a href="{{ $zeile['link'] }}" style="color:#1d4ed8; text-decoration:underline;">Ansehen</a>
                            </div>
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
        @endforeach

        @if ($posteingang)
        {{-- Tabellen-Button: Outlook ignoriert Padding auf einem `<a>`, ein
             gepaddeter Link waere dort nur blauer Text. Die Flaeche traegt
             deshalb die Zelle. --}}
        <tr>
            <td align="left" style="padding:8px 24px 24px 24px;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0"
                       style="border-collapse:collapse; mso-table-lspace:0pt; mso-table-rspace:0pt;">
                    <tr>
                        <td align="center" bgcolor="#1d4ed8" style="background-color:#1d4ed8; padding:12px 22px;">
                            <a href="{{ $posteingang }}"
                               style="font-family:Arial,Helvetica,sans-serif; font-size:15px; font-weight:bold; color:#ffffff; text-decoration:none; display:inline-block;">
                                Posteingang öffnen
                            </a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
        @endif

        <tr>
            <td style="padding:16px 24px 20px 24px; border-top:1px solid #e5e7eb; font-family:Arial,Helvetica,sans-serif; font-size:12px; line-height:18px; color:#6b7280;">
                {{-- Dieser Hinweis gehoert in JEDE dieser Mails: Wer sie nicht
                     will, soll nicht suchen muessen, wo man sie abstellt. --}}
                Diese Mail kommt, weil du für diese Benachrichtigungsart „E-Mail" eingeschaltet hast.
                @if ($einstellungen)
                Ändern lässt sich das unter
                <a href="{{ $einstellungen }}" style="color:#6b7280; text-decoration:underline;">Einstellungen &rarr; Benachrichtigungen</a>.
                @endif
            </td>
        </tr>

    </table>
    <!--[if mso]></td></tr></table><![endif]-->

</td>
</tr>
</table>

</body>
</html>
