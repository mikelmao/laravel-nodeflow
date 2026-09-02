<?php

namespace Nodeflow\Facts;

enum MissingFactBehavior: string
{
    case RouteNo = 'route_no';
    case RouteYes = 'route_yes';
    case Fail = 'fail';
}
