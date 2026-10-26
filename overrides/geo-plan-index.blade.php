@extends('admin.layouts.app')

@section('content')
    {{-- GEO 方案生成器（本地扩展）：内容由 GeoPlanReport 渲染为片段，嵌在后台布局内 --}}
    {!! $planHtml !!}
@endsection
