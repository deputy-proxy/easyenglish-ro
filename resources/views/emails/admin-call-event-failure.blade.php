WhatsApp call-link generation failed for a confirmed EasyEnglish booking.

Booking: {{ $details['booking_reference'] }}
Customer: {{ $details['customer_name'] }} ({{ $details['customer_email'] }}, {{ $details['customer_phone'] }})
Teacher: {{ $details['teacher_name'] }}
Session: {{ $details['session_start'] }} to {{ $details['session_end'] }} (Europe/Bucharest)
Call-event status: {{ $details['status'] }}
Failure category: {{ $details['failure_category'] }}
Details: {{ $details['failure_details'] }}
Attempts: {{ $details['attempts'] }}
