import { useCallback, useEffect, useState } from "react";

/**
 * Dasselbe Wort wie `POSTEINGANG_GEAENDERT` in `@peppermint-digital/inbox`
 * (pm #10227). Wörtlich doppelt, weil keines der beiden Pakete das andere
 * voraussetzt.
 */
export const POSTEINGANG_GEAENDERT = "peppermint:posteingang-geaendert";

export interface PosteingangZahlOptionen {
  /** Woher die Zahl kommt. Vorgabe: `/posteingang/ungelesen` */
  countUrl?: string;
  /** Vorbefüllung aus Server-Props — spart die erste Abfrage. */
  initialCount?: number;
  /** Abfragetakt in Millisekunden. 0 schaltet das Nachladen ab. */
  pollMs?: number;
  /** `false`: gar nicht zählen (die Zahl kommt von anderswo). */
  aktiv?: boolean;
}

/**
 * Die Zahl ungelesener Einträge im Posteingang, über alle Systeme.
 *
 * Bis v0.34.0 steckte das im {@link InboxButton} und kam nicht heraus. Am
 * Telefon steht der Posteingang im Manager aber in einem Drei-Punkte-Menü
 * (pm #10233), und dessen Symbol braucht dieselbe Zahl — ohne einen zweiten,
 * leicht abweichenden Abruf im Produkt.
 *
 * Gezählt wird beim Einhängen, im Takt `pollMs`, sofort nach
 * `peppermint:posteingang-geaendert` und nach „Zurück" aus dem
 * Browser-Zwischenspeicher (`pageshow` mit `persisted`, pm #10227).
 */
export function usePosteingangZahl({
  countUrl = "/posteingang/ungelesen",
  initialCount = 0,
  pollMs = 60_000,
  aktiv = true,
}: PosteingangZahlOptionen = {}): number {
  const [anzahl, setAnzahl] = useState(initialCount);

  const holen = useCallback(async (): Promise<void> => {
    try {
      const antwort = await fetch(countUrl, {
        headers: {
          Accept: "application/json",
          "X-Requested-With": "XMLHttpRequest",
        },
      });

      if (!antwort.ok) {
        return;
      }

      const zahl = Number((await antwort.json()).unread_count);

      if (Number.isFinite(zahl)) {
        setAnzahl(zahl);
      }
    } catch {
      // Ein Netzaussetzer lässt die letzte bekannte Zahl stehen. Eine
      // Null wäre die falsche Aussage — sie hieße „alles gelesen".
    }
  }, [countUrl]);

  useEffect(() => {
    if (!aktiv) {
      return;
    }

    void holen();

    if (pollMs <= 0) {
      return;
    }

    const takt = setInterval(() => void holen(), pollMs);

    return () => clearInterval(takt);
  }, [holen, pollMs, aktiv]);

  /*
   * Sofort neu zählen, wenn sich etwas geändert hat (pm #10227).
   *
   * - `peppermint:posteingang-geaendert` löst die Posteingangs-Seite aus
   *   (`@peppermint-digital/inbox` ab v0.8.0) nach jedem Abhaken und
   *   Löschen.
   * - `pageshow` mit `persisted`: Wer eine fremde Zeile öffnet, verlässt
   *   die Anwendung. Mit „Zurück" holt der Browser die Seite unverändert
   *   aus seinem Zwischenspeicher — samt der alten Zahl.
   */
  useEffect(() => {
    if (!aktiv || typeof window === "undefined") {
      return;
    }

    const neu = (): void => void holen();
    const zurueck = (ereignis: PageTransitionEvent): void => {
      if (ereignis.persisted) {
        void holen();
      }
    };

    window.addEventListener(POSTEINGANG_GEAENDERT, neu);
    window.addEventListener("pageshow", zurueck);

    return () => {
      window.removeEventListener(POSTEINGANG_GEAENDERT, neu);
      window.removeEventListener("pageshow", zurueck);
    };
  }, [holen, aktiv]);

  return anzahl;
}
