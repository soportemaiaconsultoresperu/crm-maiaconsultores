<?php
namespace App\Enums\Courses;
enum AcademicDocumentStatus:string{case PendingGeneration='pending_generation';case Current='current';case Annulled='annulled';case Replaced='replaced';case Failed='failed';}
