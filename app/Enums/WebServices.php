<?php

namespace App\Enums;

enum WebServices: string
{
    case SUTRAN      = "Sutran";
    case OSINERGMIN   = "Osinergmin";
    case TRACKLOG     = "Tracklog";

    public function labels(): string
    {
        return match ($this) {
            WebServices::SUTRAN         => "🚛 Sutran",
            WebServices::OSINERGMIN       => "🏭 Osinergmin",
            WebServices::TRACKLOG         => "📍 Tracklog",
        };
    }

    public function labelPowergridFilter(): string
    {
        return $this->labels();
    }
}
