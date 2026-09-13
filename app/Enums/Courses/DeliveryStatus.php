<?php
namespace App\Enums\Courses;
enum DeliveryStatus:string{case Pending='pending';case Sent='sent';case Failed='failed';case Discarded='discarded';}
