<?php
namespace App\Enums\Courses;
enum PaymentStatus:string{case Pending='pending';case Partial='partial';case Paid='paid';case Waived='waived';case Refunded='refunded';}
