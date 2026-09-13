{{--
    Plain-text part of App\Mail\GenericEmail.

    The body is echoed raw on purpose: this is a text/plain MIME part, so
    HTML-escaping it would corrupt legitimate literal text ("Maia & Consultores"
    would become "Maia &amp; Consultores"). The transport encodes the part.
--}}
{!! $body !!}
