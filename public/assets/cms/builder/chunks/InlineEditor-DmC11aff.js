import{r as n,j as o,E as h}from"../builder.js";const d=n.memo(({content:a,onSave:s,onCancel:l,placeholder:r="Start typing...",style:c={}})=>{const i=n.useRef(null),u=n.useRef(null),m=n.useCallback(()=>{if(i.current){const e=i.current.getContent();s(e)}},[s]),g=n.useCallback(e=>{e.key==="Escape"&&(e.preventDefault(),l())},[l]);return n.useEffect(()=>{const e=setTimeout(()=>{i.current&&i.current.focus()},100);return()=>clearTimeout(e)},[]),o.jsx("div",{ref:u,style:{...c,minHeight:"1em"},onClick:e=>e.stopPropagation(),onKeyDown:e=>{e.stopPropagation(),g(e.nativeEvent)},children:o.jsx(h,{tinymceScriptSrc:"/assets/cms/tinymce/tinymce.min.js",licenseKey:"gpl",onInit:(e,t)=>{i.current=t},initialValue:a||"",init:{inline:!0,menubar:!1,statusbar:!1,placeholder:r,toolbar:"blocks | bold italic | alignleft aligncenter alignright | bullist numlist | link | code",plugins:["link","lists","code"],block_formats:"Paragraph=p; Heading 1=h1; Heading 2=h2; Heading 3=h3; Heading 4=h4; Heading 5=h5; Heading 6=h6",valid_elements:"p[style],h1[style],h2[style],h3[style],h4[style],h5[style],h6[style],br,strong/b,em/i,a[href|target|rel],ul,ol,li,span[style]",valid_styles:{"*":"text-align"},paste_as_text:!1,toolbar_mode:"floating",toolbar_location:"top",auto_focus:!0,content_style:`
            body {
              font-family: inherit;
              font-size: inherit;
              line-height: inherit;
              color: inherit;
              margin: 0;
              padding: 0;
            }
            p { margin: 0 0 0.5em 0; }
            ul, ol { margin: 0.5em 0; padding-left: 1.5em; }
            li { margin: 0.25em 0; }
            h1, h2, h3, h4, h5, h6 { margin: 0 0 0.5em 0; }
          `,setup:e=>{e.on("blur",()=>{setTimeout(()=>{const t=document.activeElement;t&&(t.closest(".tox-toolbar")||t.closest(".tox-dialog")||t.closest(".tox-tinymce-inline"))||m()},100)}),e.on("keydown",t=>{t.key==="Escape"&&(t.preventDefault(),l())})}}})})});d.displayName="InlineEditor";export{d as default};
