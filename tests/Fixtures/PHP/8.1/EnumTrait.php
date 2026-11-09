<?php

trait Label
{
    public function label(): string
    {
        return $this->name;
    }
}

trait Title
{
    public function label(): string
    {
        return 'title';
    }
}

enum Item
{
    use Label, Title {
        Label::label insteadof Title;
        Title::label as heading;
    }

    case Only;
}
