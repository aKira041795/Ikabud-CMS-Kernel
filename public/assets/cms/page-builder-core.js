function cmsPageBuilder() {
    const bootEl = document.getElementById('cms-page-builder-boot');
    let boot = {};
    try { boot = bootEl ? JSON.parse(bootEl.textContent || '{}') : {}; } catch (e) { boot = {}; }
    const defaultDoc = {id:'doc_root',type:'document',kind:'document',version:1,props:{},style:{},responsive:{},visibility:{},meta:{},children:[]};
    return {
        saving:false, message:{text:'',type:'success'},
        contentTemplates: Array.isArray(boot.contentTemplates)?boot.contentTemplates:[],
        builderDocumentId: Number(boot.builderDocumentId||0),
        form: boot.form&&typeof boot.form==='object'?boot.form:{id:null,title:'',slug:'',excerpt:'',status:'draft',published_at:'',selected_template:'default'},
        pageSettings: boot.pageSettings&&typeof boot.pageSettings==='object'?boot.pageSettings:{},
        revisions: Array.isArray(boot.revisions)?boot.revisions:[],
        reusableSections: Array.isArray(boot.reusableSections)?boot.reusableSections:[],
        builderTemplates: Array.isArray(boot.builderTemplates)?boot.builderTemplates:[],
        widgetTypes: Array.isArray(boot.widgetTypes)?boot.widgetTypes:[],
        dynamicSources: Array.isArray(boot.dynamicSources)?boot.dynamicSources:[],
        previewHtml:'', selectedNodeId:null, dirty:false, autosaveStatus:'Saved', autosaveTimer:null,
        deviceMode:'desktop', undoStack:[], redoStack:[], dragState:null, _nodeCounter:0,
        selectedCategory:null, showAddWidgetMenu:null,
        doc: boot.doc&&boot.doc.document?boot.doc.document:defaultDoc,
        leftTab:'elements', inspectorTab:'content', widgetSearch:'', showHistory:false,
        sectionLayouts:[
            {type:'single',label:'1 Column',icon:'flex',desc:'Flex column'},
            {type:'two-col',label:'2 Columns',icon:'flex',desc:'Flex row, 2 containers'},
            {type:'three-col',label:'3 Columns',icon:'flex',desc:'Flex row, 3 containers'},
            {type:'sidebar',label:'Sidebar',icon:'flex',desc:'70/30 flex split'},
            {type:'hero',label:'Hero',icon:'flex',desc:'Centered flex section'},
            {type:'grid-2x2',label:'Grid 2\u00d72',icon:'grid',desc:'2-column CSS grid'},
            {type:'grid-3x2',label:'Grid 3\u00d72',icon:'grid',desc:'3-column CSS grid'}
        ],
        sectionPatterns:[
            {id:'hero',label:'Hero',desc:'Large heading + CTA',icon:'\uD83C\uDFC1'},
            {id:'features',label:'Features',desc:'3-col feature grid',icon:'\u2605'},
            {id:'cta',label:'CTA',desc:'Call to action',icon:'\uD83D\uDD14'},
            {id:'testimonial',label:'Testimonial',desc:'Quote block',icon:'\uD83D\uDCAC'},
            {id:'faq',label:'FAQ',desc:'Q&A pairs',icon:'\u2753'},
            {id:'contact',label:'Contact',desc:'Contact strip',icon:'\u2709'},
            {id:'posts',label:'Posts',desc:'Latest posts',icon:'\uD83D\uDCC4'}
        ],
        genId(){return 'n_'+Date.now().toString(36)+'_'+(++this._nodeCounter);},
        autoSlug(){if((this.form.slug||'').trim()!=='')return;this.markDirty();this.form.slug=(this.form.title||'').toLowerCase().replace(/[^a-z0-9\s-]/g,'').trim().replace(/\s+/g,'-').replace(/-+/g,'-');},
        markDirty(){this.dirty=true;this.autosaveStatus='Unsaved';},
        pushUndo(){if(this.undoStack.length>50)this.undoStack.shift();this.undoStack.push(JSON.stringify(this.doc));this.redoStack=[];},
        undo(){if(!this.undoStack.length)return;this.redoStack.push(JSON.stringify(this.doc));this.doc=JSON.parse(this.undoStack.pop());this.markDirty();},
        redo(){if(!this.redoStack.length)return;this.undoStack.push(JSON.stringify(this.doc));this.doc=JSON.parse(this.redoStack.pop());this.markDirty();},
        primaryActionLabel(){return this.form.status==='published'?'Update Page':'Publish';},
        countNodes(arr){if(!Array.isArray(arr))return 0;let c=arr.length;arr.forEach(n=>{if(Array.isArray(n.children))c+=this.countNodes(n.children);});return c;},
        nodeLabel(n){const m={heading:'Heading',text:'Text',image:'Image',button:'Button',divider:'Divider',spacer:'Spacer',section:'Section',columns:'Columns',container:'Container',dynamic_field:'Dynamic Field',posts_list:'Posts List',embed:'Embed',html:'HTML',quote:'Quote',gallery:'Gallery',list:'List'};return m[n.type]||n.type||'Node';},
        widgetSvg(t){
            const s='<svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">';
            const e='</svg>';
            const icons={
                heading:s+'<path stroke-linecap="round" d="M4 6h16M4 12h8m-8 6h16"/>'+e,
                text:s+'<path stroke-linecap="round" d="M4 6h16M4 10h16M4 14h10M4 18h12"/>'+e,
                image:s+'<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/>'+e,
                button:s+'<rect x="3" y="8" width="18" height="8" rx="4"/><path stroke-linecap="round" d="M8 12h8"/>'+e,
                divider:s+'<path stroke-linecap="round" d="M3 12h18"/>'+e,
                spacer:s+'<path stroke-linecap="round" d="M12 5v14M8 8l4-3 4 3M8 16l4 3 4-3"/>'+e,
                section:s+'<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18"/>'+e,
                columns:s+'<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 3v18M15 3v18"/>'+e,
                container:s+'<rect x="3" y="3" width="18" height="18" rx="2" stroke-dasharray="4 2"/>'+e,
                dynamic_field:s+'<path stroke-linecap="round" d="M13 2 3 14h9l-1 8 10-12h-9l1-8"/>'+e,
                posts_list:s+'<path stroke-linecap="round" d="M9 5h11M9 12h11M9 19h11M5 5h.01M5 12h.01M5 19h.01"/>'+e,
                embed:s+'<path stroke-linecap="round" stroke-linejoin="round" d="m13.828 10.172-3.656 3.656m0-3.656 3.656 3.656M7 20h10a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2Z"/>'+e,
                html:s+'<path stroke-linecap="round" d="m7 8-4 4 4 4m10-8 4 4-4 4M14 4l-4 16"/>'+e,
                quote:s+'<path stroke-linecap="round" d="M3 21c3 0 7-1 7-8V5c0-1.25-.756-2.017-2-2H4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2 1 0 1 0 1 1v1c0 1-1 2-2 2s-1 .008-1 1.031V21zm12 0c3 0 7-1 7-8V5c0-1.25-.757-2.017-2-2h-4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2h.75c0 2.25.25 4-2.75 4v3z"/>'+e,
                gallery:s+'<rect x="2" y="2" width="8" height="8" rx="1"/><rect x="14" y="2" width="8" height="8" rx="1"/><rect x="2" y="14" width="8" height="8" rx="1"/><rect x="14" y="14" width="8" height="8" rx="1"/>'+e,
                list:s+'<path stroke-linecap="round" d="M9 6h11M9 12h11M9 18h11M5 6h.01M5 12h.01M5 18h.01"/>'+e
            };
            return icons[t]||icons.text;
        },
        widgetIcon(t){const m={heading:'H',text:'T',image:'\uD83D\uDCF7',button:'\uD83D\uDD18',divider:'\u2014',spacer:'\u2195',section:'\u25A2',columns:'\u25A3',container:'\u25A1',dynamic_field:'\u26A1',posts_list:'\uD83D\uDCC4',embed:'\uD83D\uDD17',html:'</>',quote:'\u201C',gallery:'\uD83C\uDF04',list:'\u2022'};return m[t]||'\u25CF';},
        nodeColor(t){
            const m={section:'violet',columns:'blue',container:'sky',heading:'amber',text:'slate',image:'emerald',button:'violet',divider:'gray',spacer:'gray',dynamic_field:'orange',posts_list:'cyan',embed:'indigo',html:'rose',quote:'teal',gallery:'emerald',list:'slate'};
            return m[t]||'gray';
        },
        nodeBadgeClasses(t){
            const c=this.nodeColor(t);
            const m={violet:'bg-violet-100 text-violet-700',blue:'bg-blue-100 text-blue-700',sky:'bg-sky-100 text-sky-700',amber:'bg-amber-100 text-amber-700',slate:'bg-slate-100 text-slate-700',emerald:'bg-emerald-100 text-emerald-700',gray:'bg-gray-100 text-gray-600',orange:'bg-orange-100 text-orange-700',cyan:'bg-cyan-100 text-cyan-700',indigo:'bg-indigo-100 text-indigo-700',rose:'bg-rose-100 text-rose-700',teal:'bg-teal-100 text-teal-700'};
            return m[c]||m.gray;
        },
        nodeRingClass(t){
            const c=this.nodeColor(t);
            const m={violet:'ring-violet-400',blue:'ring-blue-400',sky:'ring-sky-400',amber:'ring-amber-400',slate:'ring-slate-400',emerald:'ring-emerald-400',gray:'ring-gray-300',orange:'ring-orange-400',cyan:'ring-cyan-400',indigo:'ring-indigo-400',rose:'ring-rose-400',teal:'ring-teal-400'};
            return m[c]||m.gray;
        },
        categoryLabel(cat){
            const m={content:'Content',layout:'Layout',media:'Media',dynamic:'Dynamic',advanced:'Advanced'};
            return m[cat]||cat;
        },
        categoryColor(cat){
            const m={content:'violet',layout:'blue',media:'emerald',dynamic:'orange',advanced:'rose'};
            return m[cat]||'gray';
        },
        widgetCategories(){const c=[];this.widgetTypes.forEach(w=>{if(!c.includes(w.category))c.push(w.category);});return c;},
        filteredWidgets(cat){const s=this.widgetSearch.toLowerCase();const sc=this.selectedCategory;return this.widgetTypes.filter(w=>w.category===cat&&(!sc||sc===cat)&&(!s||w.label.toLowerCase().includes(s)||(w.description||'').toLowerCase().includes(s)));},
        allFilteredWidgets(){const s=this.widgetSearch.toLowerCase();const sc=this.selectedCategory;return this.widgetTypes.filter(w=>(!sc||w.category===sc)&&(!s||w.label.toLowerCase().includes(s)||(w.description||'').toLowerCase().includes(s)));},
        selectedNode(){if(!this.selectedNodeId)return null;return this.findNode(this.doc.children,this.selectedNodeId);},
        selectNode(id){this.selectedNodeId=id;this.inspectorTab='content';},
        findNode(arr,id){for(const n of arr){if(n.id===id)return n;if(Array.isArray(n.children)){const f=this.findNode(n.children,id);if(f)return f;}}return null;},
        findParentArray(arr,id){for(let i=0;i<arr.length;i++){if(arr[i].id===id)return{arr:arr,idx:i};if(Array.isArray(arr[i].children)){const f=this.findParentArray(arr[i].children,id);if(f)return f;}}return null;},
        esc(s){const d=document.createElement('div');d.textContent=String(s);return d.innerHTML;},
        safeObject(v){return v&&typeof v==='object'&&!Array.isArray(v)?v:{};},
        normalizeNode(node){
            if(!node||typeof node!=='object')return node;
            node.props=this.safeObject(node.props);
            node.style=this.safeObject(node.style);
            node.responsive=this.safeObject(node.responsive);
            node.visibility=this.safeObject(node.visibility);
            node.meta=this.safeObject(node.meta);
            node.children=Array.isArray(node.children)?node.children.map((child)=>this.normalizeNode(child)):[];
            return node;
        },
        styleString(styleObj){
            return Object.entries(styleObj)
                .filter(([,value])=>value!==null&&value!==undefined&&value!=='')
                .map(([key,value])=>`${key}:${value}`)
                .join(';');
        },
        responsiveValue(node,key){
            if(!node||!node.responsive)return '';
            const order=['desktop','tablet','mobile'];
            const max=order.indexOf(this.deviceMode);
            for(let i=max;i>=0;i--){
                const bucket=node.responsive[order[i]];
                if(bucket&&bucket[key]!==undefined&&bucket[key]!==null&&bucket[key]!=='')return bucket[key];
            }
            return '';
        },
        frameStyle(node, defaults){
            const style=this.safeObject(node&&node.style);
            return this.styleString(Object.assign({}, defaults||{}, {
                background: style.background || (defaults&&defaults.background) || null,
                color: style.color || (defaults&&defaults.color) || null,
                padding: this.responsiveValue(node,'padding') || style.padding || (defaults&&defaults.padding) || null,
                margin: style.margin || null,
                borderRadius: style.borderRadius || (defaults&&defaults.borderRadius) || null,
                textAlign: style.textAlign || null,
                fontSize: this.responsiveValue(node,'font_size') || style.fontSize || null
            }));
        },
        isContainer(node){return node&&(node.kind==='section'||['section','columns','container'].includes(node.type));},
        containerLayoutCSS(node){
            const p=this.safeObject(node.props);
            const layout=p.layout||'flex';
            if(layout==='grid'){
                return{
                    display:'grid',
                    gridTemplateColumns:'repeat('+(p.grid_columns||2)+', 1fr)',
                    gridTemplateRows:p.grid_rows?'repeat('+p.grid_rows+', auto)':'',
                    gap:p.gap||'16px',
                    justifyItems:p.justify_items||'',
                    alignItems:p.align_items||''
                };
            }
            return{
                display:'flex',
                flexDirection:p.direction||'column',
                justifyContent:p.justify||'',
                alignItems:p.align||'',
                gap:p.gap||'16px',
                flexWrap:p.wrap||''
            };
        },
        makeNode(type){
            const sectionTypes=['section','columns','container'];
            const kind=sectionTypes.includes(type)?'section':'widget';
            const props={};
            if(type==='heading'){props.text='Heading';props.level='h2';}
            else if(type==='text'){props.html='<p>Text content goes here.</p>';}
            else if(type==='image'){props.url='';props.alt='';props.caption='';}
            else if(type==='button'){props.text='Click me';props.url='#';props.style='primary';}
            else if(type==='divider'){props.style='solid';}
            else if(type==='spacer'){props.height='48px';}
            else if(type==='dynamic_field'){props.source='page.title';props.fallback='';}
            else if(type==='posts_list'){props.count=5;props.type='post';}
            else if(type==='quote'){props.text='This is a quote.';props.author='Author';}
            else if(type==='list'){props.items='Item one\nItem two\nItem three';props.ordered=false;}
            else if(type==='gallery'){props.images=[];props.columns=3;}
            else if(type==='embed'){props.url='';props.type='youtube';}
            else if(type==='html'){props.code='<div>Custom HTML</div>';}
            if(kind==='section'){
                props.layout=props.layout||'flex';
                props.direction=props.direction||'column';
                props.gap=props.gap||'16px';
                props.justify=props.justify||'';
                props.align=props.align||'';
                props.wrap=props.wrap||'';
                props.grid_columns=props.grid_columns||2;
                props.grid_rows=props.grid_rows||'';
                props.justify_items=props.justify_items||'';
                props.align_items=props.align_items||'';
                props.width_mode=props.width_mode||'boxed';
                props.min_height=props.min_height||'';
            }
            return{id:this.genId(),type:type,kind:kind,version:1,props:props,style:{},responsive:{},visibility:{},meta:{},children:kind==='section'?[]:[]};
        },
        insertNode(type,parentId){
            this.pushUndo();const node=this.makeNode(type);
            if(parentId){const p=this.findNode(this.doc.children,parentId);if(p){if(!Array.isArray(p.children))p.children=[];p.children.push(node);}}
            else{this.doc.children.push(node);}
            this.selectedNodeId=node.id;this.markDirty();
        },
        insertNodeAt(type,idx){this.pushUndo();const node=this.makeNode(type);this.doc.children.splice(idx,0,node);this.selectedNodeId=node.id;this.markDirty();},
        insertChildNode(parentId,type){this.insertNode(type,parentId);},
        insertSectionLayout(layoutType){
            this.pushUndo();const sec=this.makeNode('section');
            if(layoutType==='two-col'){
                sec.props.layout='flex';sec.props.direction='row';sec.props.gap='16px';
                for(let i=0;i<2;i++){const c=this.makeNode('container');c.props.layout='flex';c.props.direction='column';sec.children.push(c);}
            } else if(layoutType==='three-col'){
                sec.props.layout='flex';sec.props.direction='row';sec.props.gap='16px';
                for(let i=0;i<3;i++){const c=this.makeNode('container');c.props.layout='flex';c.props.direction='column';sec.children.push(c);}
            } else if(layoutType==='grid-2x2'){
                sec.props.layout='grid';sec.props.grid_columns=2;sec.props.gap='16px';
                for(let i=0;i<4;i++){const c=this.makeNode('container');sec.children.push(c);}
            } else if(layoutType==='grid-3x2'){
                sec.props.layout='grid';sec.props.grid_columns=3;sec.props.gap='16px';
                for(let i=0;i<6;i++){const c=this.makeNode('container');sec.children.push(c);}
            } else if(layoutType==='hero'){
                sec.props.layout='flex';sec.props.direction='column';sec.props.align='center';sec.props.justify='center';sec.props.min_height='400px';
            } else if(layoutType==='sidebar'){
                sec.props.layout='flex';sec.props.direction='row';sec.props.gap='24px';
                const main=this.makeNode('container');main.props.layout='flex';main.props.direction='column';main.style.flex='1 1 70%';
                const side=this.makeNode('container');side.props.layout='flex';side.props.direction='column';side.style.flex='0 0 30%';
                sec.children.push(main,side);
            } else {
                sec.props.layout='flex';sec.props.direction='column';
            }
            this.doc.children.push(sec);this.selectedNodeId=sec.id;this.markDirty();
        },
        insertSectionBefore(idx){this.pushUndo();const sec=this.makeNode('section');this.doc.children.splice(idx,0,sec);this.selectedNodeId=sec.id;this.markDirty();},
        insertSectionAfter(idx){this.pushUndo();const sec=this.makeNode('section');this.doc.children.splice(idx+1,0,sec);this.selectedNodeId=sec.id;this.markDirty();},
        insertPattern(patId){
            this.pushUndo();const sec=this.makeNode('section');
            if(patId==='hero'){sec.props.layout='flex';sec.props.direction='column';sec.props.align='center';sec.props.justify='center';sec.props.min_height='360px';const h=this.makeNode('heading');h.props.text='Welcome to our site';h.props.level='h1';const t=this.makeNode('text');t.props.html='<p>A brief introduction to your page.</p>';const b=this.makeNode('button');b.props.text='Get Started';sec.children.push(h,t,b);}
            else if(patId==='features'){sec.props.layout='flex';sec.props.direction='row';sec.props.gap='20px';for(let i=1;i<=3;i++){const c=this.makeNode('container');c.props.layout='flex';c.props.direction='column';const fh=this.makeNode('heading');fh.props.text='Feature '+i;fh.props.level='h3';const ft=this.makeNode('text');ft.props.html='<p>Feature description.</p>';c.children.push(fh,ft);sec.children.push(c);}}
            else if(patId==='cta'){const h=this.makeNode('heading');h.props.text='Ready to get started?';h.props.level='h2';const b=this.makeNode('button');b.props.text='Contact Us';sec.children.push(h,b);}
            else if(patId==='testimonial'){const q=this.makeNode('text');q.props.html='<blockquote>"This product changed everything."</blockquote><p>\u2014 Customer</p>';sec.children.push(q);}
            else if(patId==='faq'){for(let i=1;i<=3;i++){const fh=this.makeNode('heading');fh.props.text='Question '+i+'?';fh.props.level='h3';const ft=this.makeNode('text');ft.props.html='<p>Answer '+i+'.</p>';sec.children.push(fh,ft);}}
            else if(patId==='contact'){const h=this.makeNode('heading');h.props.text='Contact Us';h.props.level='h2';const t=this.makeNode('text');t.props.html='<p>Email: hello@example.com</p>';sec.children.push(h,t);}
            else if(patId==='posts'){sec.children.push(this.makeNode('posts_list'));}
            this.doc.children.push(sec);this.selectedNodeId=sec.id;this.markDirty();
        },
        handleNavigatorClick(e){
            const btn=e.target.closest('[data-action="select"]');
            if(btn&&btn.dataset.id){e.stopPropagation();this.selectNode(btn.dataset.id);}
        },
        handleCanvasClick(e){
            const el=e.target.closest('[data-action]');
            if(!el)return;
            e.stopPropagation();
            const action=el.dataset.action;const id=el.dataset.id;const parent=el.dataset.parent;
            if(action==='duplicate'&&id)this.duplicateNode(id);
            else if(action==='remove'&&id)this.removeNode(id);
            else if(action==='add-child'&&parent)this.showAddWidget(parent);
            const nodeEl=e.target.closest('[data-node-id]');
            if(nodeEl&&!el)this.selectNode(nodeEl.dataset.nodeId);
        },
        showAddWidget(parentId){const type=window.prompt('Widget or container type:\nWidgets: heading, text, image, button, divider, spacer, quote, list, gallery, embed, html\nContainers: container','text');if(type)this.insertNode(type.trim(),parentId);},
        removeNode(id){this.pushUndo();const loc=this.findParentArray(this.doc.children,id);if(loc){loc.arr.splice(loc.idx,1);}if(this.selectedNodeId===id)this.selectedNodeId=null;this.markDirty();},
        duplicateNode(id){this.pushUndo();const loc=this.findParentArray(this.doc.children,id);if(!loc)return;const copy=JSON.parse(JSON.stringify(loc.arr[loc.idx]));const reId=(n)=>{n.id=this.genId();if(Array.isArray(n.children))n.children.forEach(reId);};reId(copy);loc.arr.splice(loc.idx+1,0,copy);this.selectedNodeId=copy.id;this.markDirty();},
        moveNode(id,dir){const loc=this.findParentArray(this.doc.children,id);if(!loc)return;const ni=loc.idx+dir;if(ni<0||ni>=loc.arr.length)return;this.pushUndo();const tmp=loc.arr[loc.idx];loc.arr[loc.idx]=loc.arr[ni];loc.arr[ni]=tmp;this.markDirty();},
        onDragStart(e,nodeId,parentId,idx){this.dragState={nodeId:nodeId,parentId:parentId,idx:idx};e.dataTransfer.effectAllowed='move';},
        onWidgetDragStart(e,type){e.dataTransfer.setData('text/plain',type);e.dataTransfer.effectAllowed='copy';},
        onCanvasDragOver(e){e.preventDefault();e.dataTransfer.dropEffect=this.dragState?'move':'copy';},
        onDrop(e,targetParentId,targetIdx){
            e.preventDefault();
            if(this.dragState){
                const src=this.dragState;this.dragState=null;
                if(src.nodeId===targetParentId)return;
                this.pushUndo();
                const srcLoc=this.findParentArray(this.doc.children,src.nodeId);if(!srcLoc)return;
                const node=srcLoc.arr.splice(srcLoc.idx,1)[0];
                let targetArr=this.doc.children;
                if(targetParentId){const p=this.findNode(this.doc.children,targetParentId);targetArr=p&&Array.isArray(p.children)?p.children:this.doc.children;}
                const ins=Math.min(targetIdx,targetArr.length);targetArr.splice(ins,0,node);this.markDirty();
            } else {
                const type=e.dataTransfer.getData('text/plain');
                if(type){if(targetParentId)this.insertNode(type,targetParentId);else this.insertNodeAt(type,targetIdx);}
            }
        },
        setStyle(key,val){const n=this.selectedNode();if(!n)return;if(!n.style)n.style={};this.pushUndo();n.style[key]=val;this.markDirty();},
        setMeta(key,val){const n=this.selectedNode();if(!n)return;if(!n.meta)n.meta={};this.pushUndo();n.meta[key]=val;this.markDirty();},
        setProp(id,key,val){const n=this.findNode(this.doc.children,id);if(!n)return;if(!n.props)n.props={};n.props[key]=val;this.markDirty();},
        setResponsive(node,key,value){if(!node)return;this.pushUndo();if(!node.responsive)node.responsive={};if(!node.responsive[this.deviceMode])node.responsive[this.deviceMode]={};node.responsive[this.deviceMode][key]=value;this.markDirty();},
        getResponsive(key){const n=this.selectedNode();if(!n||!n.responsive||!n.responsive[this.deviceMode])return '';return n.responsive[this.deviceMode][key]||'';},
        buildCanonicalDocument(){return{schema_version:'1.0',document:{...this.doc,props:{...this.doc.props,title:this.form.title||''}}};},
        renderCanvasPreview(node){
            const t=node.type;const p=node.props||{};
            if(t==='heading'){
                const tag=p.level||'h2';
                return '<'+tag+' style="'+this.frameStyle(node,{margin:'0',fontWeight:tag==='h1'?'800':'700',letterSpacing:'-0.02em',color:'#0f172a'})+'">'+this.esc(p.text||'Heading')+'</'+tag+'>';
            }
            if(t==='text')return '<div style="'+this.frameStyle(node,{color:'#334155',fontSize:'15px',lineHeight:'1.7'})+'">'+(p.html||'<p>Text</p>')+'</div>';
            if(t==='image'){
                if(p.url)return '<figure style="'+this.frameStyle(node,{margin:'0'})+'"><img src="'+this.esc(p.url)+'" alt="'+this.esc(p.alt||'')+'" style="max-width:100%;width:100%;border-radius:'+(this.safeObject(node.style).borderRadius||'12px')+'"><figcaption style="margin-top:8px;color:'+(this.safeObject(node.style).color||'#64748b')+';font-size:'+(this.safeObject(node.style).fontSize||'12px')+'">'+this.esc(p.caption||'')+'</figcaption></figure>';
                return '<div style="'+this.frameStyle(node,{background:'#e2e8f0',borderRadius:'12px',padding:'28px',textAlign:'center',color:'#64748b',fontSize:'12px'})+'">Image placeholder</div>';
            }
            if(t==='button'){
                const style=this.safeObject(node.style);
                const variant=p.style||'primary';
                const buttonStyle=this.styleString({
                    display:'inline-block',
                    padding:style.padding||'10px 22px',
                    background:style.background||(variant==='secondary'?'#334155':variant==='outline'?'transparent':'#7c3aed'),
                    color:style.color||(variant==='outline'?'#7c3aed':'#ffffff'),
                    border:variant==='outline'?'1px solid '+(style.color||'#7c3aed'):'1px solid transparent',
                    borderRadius:style.borderRadius||'10px',
                    fontSize:this.responsiveValue(node,'font_size')||style.fontSize||'14px',
                    fontWeight:'600',
                    cursor:'default',
                    textDecoration:'none'
                });
                return '<div style="'+this.frameStyle(node,{textAlign:style.textAlign||'left'})+'"><button style="'+buttonStyle+'">'+this.esc(p.text||'Button')+'</button></div>';
            }
            if(t==='divider')return '<hr style="'+this.styleString({border:'none',borderTop:'1px solid '+(this.safeObject(node.style).color||'#cbd5e1'),margin:this.safeObject(node.style).margin||'8px 0'})+'">';
            if(t==='spacer')return '<div style="height:'+(p.height||'48px')+'"></div>';
            if(t==='dynamic_field')return '<span style="'+this.frameStyle(node,{display:'inline-block',padding:'4px 8px',background:'#fef3c7',border:'1px dashed #f59e0b',borderRadius:'4px',fontSize:'11px',color:'#92400e'})+'">⚡ '+(p.source||'dynamic')+'</span>';
            if(t==='posts_list')return '<div style="'+this.frameStyle(node,{padding:'14px 16px',background:'#f0f9ff',borderRadius:'10px',border:'1px solid #bae6fd'})+'"><div style="display:flex;align-items:center;gap:8px;margin-bottom:6px"><span style="font-size:13px">📄</span><span style="font-size:12px;font-weight:600;color:#0369a1">Posts List</span></div><div style="font-size:11px;color:#64748b">'+(p.count||5)+' items &middot; '+this.esc(p.type||'post')+'</div></div>';
            if(t==='quote')return '<blockquote style="'+this.frameStyle(node,{borderLeft:'3px solid #7c3aed',paddingLeft:'16px',margin:'0',fontStyle:'italic',color:'#334155',fontSize:'15px',lineHeight:'1.6'})+'">'+this.esc(p.text||'Quote text')+(p.author?'<div style="margin-top:8px;font-style:normal;font-size:12px;font-weight:600;color:#7c3aed">&mdash; '+this.esc(p.author)+'</div>':'')+'</blockquote>';
            if(t==='list'){const items=(p.items||'').split('\n').filter(Boolean);return '<'+(p.ordered?'ol':'ul')+' style="'+this.frameStyle(node,{paddingLeft:'20px',margin:'0',color:'#334155',fontSize:'14px',lineHeight:'1.8'})+'">'+items.map(i=>'<li>'+this.esc(i)+'</li>').join('')+'</'+(p.ordered?'ol':'ul')+'>';}
            if(t==='gallery')return '<div style="'+this.frameStyle(node,{display:'grid',gridTemplateColumns:'repeat('+(p.columns||3)+',1fr)',gap:'8px',padding:'4px'})+'"><div style="background:#e2e8f0;border-radius:8px;aspect-ratio:1;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:11px">1</div><div style="background:#e2e8f0;border-radius:8px;aspect-ratio:1;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:11px">2</div><div style="background:#e2e8f0;border-radius:8px;aspect-ratio:1;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:11px">3</div></div>';
            if(t==='embed')return '<div style="'+this.frameStyle(node,{padding:'24px',background:'#f8fafc',borderRadius:'12px',border:'1px dashed #cbd5e1',textAlign:'center'})+'"><div style="font-size:20px;margin-bottom:6px">🎬</div><div style="font-size:12px;font-weight:600;color:#475569">Embed</div><div style="font-size:11px;color:#94a3b8;margin-top:2px">'+(p.url?this.esc(p.url):'No URL set')+'</div></div>';
            if(t==='html')return '<div style="'+this.frameStyle(node,{padding:'12px 16px',background:'#fff1f2',borderRadius:'10px',border:'1px solid #fecdd3',fontFamily:'monospace',fontSize:'12px',color:'#be123c',whiteSpace:'pre-wrap'})+'">'+this.esc(p.code||'<!-- HTML -->')+'</div>';
            if(t==='section'||t==='columns'||t==='container'){
                const lp=this.safeObject(node.props);const layout=lp.layout||'flex';
                const isGrid=layout==='grid';
                const bgCol=t==='section'?'#faf5ff':'#eff6ff';const borderCol=t==='section'?'#e9d5ff':'#bfdbfe';const accentCol=t==='section'?'#7c3aed':'#3b82f6';
                const layoutBadge=isGrid?'Grid '+((lp.grid_columns||2)+'\u00d7'+(lp.grid_rows||'auto')):'Flex '+(lp.direction||'column');
                const minH=lp.min_height||'60px';
                return '<div style="'+this.frameStyle(node,{background:bgCol,padding:'16px',borderRadius:'12px',minHeight:minH,border:'1px solid '+borderCol})+'"><div style="display:flex;align-items:center;gap:8px;margin-bottom:6px"><span style="font-size:11px;font-weight:700;letter-spacing:0.05em;text-transform:uppercase;color:'+accentCol+'">'+this.esc(this.nodeLabel(node))+'</span><span style="font-size:9px;padding:2px 6px;border-radius:4px;background:'+(isGrid?'#dbeafe':'#ede9fe')+';color:'+(isGrid?'#1d4ed8':'#6d28d9')+';font-weight:700;letter-spacing:0.05em;text-transform:uppercase">'+this.esc(layoutBadge)+'</span>'+(lp.gap?'<span style="font-size:9px;color:#94a3b8">gap:'+this.esc(lp.gap)+'</span>':'')+'</div><div style="font-size:11px;color:#a78bfa">Drop widgets or containers inside.</div></div>';
            }
            return '<div style="padding:10px 14px;background:#f9fafb;border-radius:8px;border:1px solid #e5e7eb;font-size:12px;color:#6b7280">'+this.esc(t)+' widget</div>';
        },
        renderCanvasNode(node, parentId, idx, depth){
            depth=depth||0;
            const sel=this.selectedNodeId===node.id;
            const isCont=this.isContainer(node);
            const ringCls=sel?'ring-2 '+this.nodeRingClass(node.type)+' bg-white shadow-sm':'hover:ring-1 hover:ring-gray-200';
            const badgeCls=this.nodeBadgeClasses(node.type);
            let h='<div class="group/n'+depth+' relative p-'+(depth>0?'3':'4')+' transition-all rounded-xl '+(depth>0?'':'m-1 ')+ringCls+'" data-node-id="'+node.id+'" data-parent-id="'+(parentId||'')+'" data-idx="'+idx+'" draggable="true">';
            h+='<div class="absolute top-'+(depth>0?'1':'2')+' right-'+(depth>0?'1':'2')+' flex items-center gap-0.5 z-10 '+(sel?'':'opacity-0 hover:opacity-100')+'" style="'+(sel?'':'opacity:0')+'">';
            h+='<span class="cursor-grab p-1 rounded-lg hover:bg-gray-100 text-gray-400"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M4 8h16M4 16h16"/></svg></span>';
            h+='<button type="button" class="p-1 rounded-lg hover:bg-gray-100 text-gray-400" data-action="duplicate" data-id="'+node.id+'"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg></button>';
            h+='<button type="button" class="p-1 rounded-lg hover:bg-red-50 text-gray-400 hover:text-red-500" data-action="remove" data-id="'+node.id+'"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg></button>';
            h+='</div>';
            h+='<div class="absolute top-'+(depth>0?'1':'2')+' left-'+(depth>0?'1.5':'2')+' z-10 flex items-center gap-1 '+(sel?'':'opacity-0')+'" style="'+(sel?'':'opacity:0')+'">';
            h+='<span class="text-['+(depth>0?'8':'9')+'px] font-bold uppercase tracking-wider px-'+(depth>0?'1.5':'2')+' py-0.5 rounded-md '+badgeCls+'">'+this.esc(this.nodeLabel(node))+'</span>';
            if(isCont){const lp=node.props||{};h+='<span class="text-[8px] font-semibold px-1 py-0.5 rounded '+((lp.layout||'flex')==='grid'?'bg-blue-100 text-blue-600':'bg-violet-100 text-violet-600')+'">'+(lp.layout||'flex')+'</span>';}
            h+='</div>';
            h+='<div class="pt-'+(depth>0?'5':'6')+'">';
            if(isCont&&Array.isArray(node.children)&&node.children.length>0){
                const layoutCSS=this.containerLayoutCSS(node);
                h+='<div style="'+this.styleString(layoutCSS)+'">';
                node.children.forEach((child,ci)=>{
                    const childFlex=(child.style&&child.style.flex)?'flex:'+child.style.flex+';':'';
                    h+='<div style="min-width:0;'+childFlex+'">';
                    h+=this.renderCanvasNode(child, node.id, ci, depth+1);
                    h+='</div>';
                });
                h+='</div>';
                h+='<button type="button" class="w-full py-2 mt-2 text-[10px] font-semibold text-violet-500 border-2 border-dashed border-violet-200 rounded-xl hover:bg-violet-50 hover:border-violet-300 transition-colors" data-action="add-child" data-parent="'+node.id+'"><svg class="w-3 h-3 inline-block mr-0.5 -mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M12 6v12M6 12h12"/></svg>Add</button>';
            } else if(isCont){
                h+=this.renderCanvasPreview(node);
                h+='<button type="button" class="w-full py-2 mt-2 text-[10px] font-semibold text-violet-500 border-2 border-dashed border-violet-200 rounded-xl hover:bg-violet-50 hover:border-violet-300 transition-colors" data-action="add-child" data-parent="'+node.id+'"><svg class="w-3 h-3 inline-block mr-0.5 -mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M12 6v12M6 12h12"/></svg>Add</button>';
            } else {
                h+=this.renderCanvasPreview(node);
            }
            h+='</div></div>';
            return h;
        },
        renderNavigatorTree(nodes, depth){
            depth=depth||0;
            if(!Array.isArray(nodes)||nodes.length===0)return '';
            let h='<div class="space-y-0.5'+(depth>0?' pl-4 border-l border-slate-700/50 ml-3 mt-0.5':'')+'">';
            nodes.forEach(nd=>{
                const sel=this.selectedNodeId===nd.id;
                const isCont=this.isContainer(nd);
                const lp=nd.props||{};
                h+='<div>';
                h+='<button type="button" class="w-full flex items-center gap-2 px-'+(depth>0?'2.5':'3')+' py-'+(depth>0?'1.5':'2')+' rounded-lg text-'+(depth>0?'xs':'sm')+' transition-colors '+(sel?'bg-violet-500/20 text-violet-200 font-semibold ring-1 ring-violet-400/30':'text-slate-300 hover:bg-slate-800')+'" data-action="select" data-id="'+nd.id+'">';
                h+='<span class="w-'+(depth>0?'4':'5')+' h-'+(depth>0?'4':'5')+' shrink-0 '+(sel?'text-violet-400':'text-slate-500')+'">'+this.widgetSvg(nd.type)+'</span>';
                h+='<span class="truncate">'+this.esc(this.nodeLabel(nd))+'</span>';
                if(isCont){h+='<span class="text-[8px] font-semibold px-1 py-0.5 rounded shrink-0 '+((lp.layout||'flex')==='grid'?'bg-blue-500/20 text-blue-300':'bg-violet-500/20 text-violet-300')+'">'+(lp.layout||'flex')+'</span>';}
                if(depth===0){h+='<span class="ml-auto text-[9px] px-1.5 py-0.5 rounded-full font-medium shrink-0 '+this.nodeBadgeClasses(nd.type)+'">'+this.esc(nd.type)+'</span>';}
                h+='</button>';
                if(Array.isArray(nd.children)&&nd.children.length>0){
                    h+=this.renderNavigatorTree(nd.children, depth+1);
                }
                h+='</div>';
            });
            h+='</div>';
            return h;
        },
        renderLeftPanel(){return '';},
        renderCanvas(){return '';},
        renderInspector(){return '';},
        _inspectorInput(id,key,val,label,type){
            type=type||'text';
            const cls='w-full text-xs bg-slate-900 border border-slate-700 text-slate-100 rounded-lg px-3 py-2 focus:border-violet-500 focus:ring-1 focus:ring-violet-500/30 outline-none transition-colors';
            const ev='oninput="this.dispatchEvent(new CustomEvent(\'node-prop\',{bubbles:true,detail:{id:\''+id+'\',key:\''+key+'\',val:this.value}}))"';
            return '<div><label class="block text-[10px] font-semibold text-slate-400 uppercase tracking-wider mb-1.5">'+this.esc(label)+'</label><input type="'+type+'" class="'+cls+'" value="'+this.esc(val)+'" '+ev+'></div>';
        },
        _inspectorTextarea(id,key,val,label,rows){
            const cls='w-full text-xs bg-slate-900 border border-slate-700 text-slate-100 rounded-lg px-3 py-2 focus:border-violet-500 focus:ring-1 focus:ring-violet-500/30 outline-none transition-colors resize-y';
            const ev='oninput="this.dispatchEvent(new CustomEvent(\'node-prop\',{bubbles:true,detail:{id:\''+id+'\',key:\''+key+'\',val:this.value}}))"';
            return '<div><label class="block text-[10px] font-semibold text-slate-400 uppercase tracking-wider mb-1.5">'+this.esc(label)+'</label><textarea class="'+cls+'" rows="'+(rows||4)+'" '+ev+'>'+this.esc(val)+'</textarea></div>';
        },
        _inspectorSelect(id,key,val,label,options){
            const cls='w-full text-xs bg-slate-900 border border-slate-700 text-slate-100 rounded-lg px-3 py-2 focus:border-violet-500 focus:ring-1 focus:ring-violet-500/30 outline-none transition-colors';
            const ev='onchange="this.dispatchEvent(new CustomEvent(\'node-prop\',{bubbles:true,detail:{id:\''+id+'\',key:\''+key+'\',val:this.value}}))"';
            let opts='';options.forEach(o=>{const ov=typeof o==='object'?o.value:o;const ol=typeof o==='object'?o.label:o;opts+='<option value="'+this.esc(ov)+'"'+(val===ov?' selected':'')+'>'+this.esc(ol)+'</option>';});
            return '<div><label class="block text-[10px] font-semibold text-slate-400 uppercase tracking-wider mb-1.5">'+this.esc(label)+'</label><select class="'+cls+'" '+ev+'>'+opts+'</select></div>';
        },
        renderInspectorContent(node){
            if(!node)return '';const t=node.type;const p=node.props||{};const id=node.id;let h='';
            if(t==='heading'){
                h+=this._inspectorInput(id,'text',p.text||'','Text');
                h+=this._inspectorSelect(id,'level',p.level||'h2','Level',['h1','h2','h3','h4','h5','h6'].map(v=>({value:v,label:v.toUpperCase()})));
            } else if(t==='text'){
                h+=this._inspectorTextarea(id,'html',p.html||'','Content',6);
            } else if(t==='image'){
                h+=this._inspectorInput(id,'url',p.url||'','Image URL');
                h+=this._inspectorInput(id,'alt',p.alt||'','Alt Text');
                h+=this._inspectorInput(id,'caption',p.caption||'','Caption');
            } else if(t==='button'){
                h+=this._inspectorInput(id,'text',p.text||'','Label');
                h+=this._inspectorInput(id,'url',p.url||'','URL');
                h+=this._inspectorSelect(id,'style',p.style||'primary','Variant',[{value:'primary',label:'Primary'},{value:'secondary',label:'Secondary'},{value:'outline',label:'Outline'}]);
            } else if(t==='spacer'){
                h+=this._inspectorInput(id,'height',p.height||'48px','Height');
            } else if(t==='dynamic_field'){
                h+=this._inspectorSelect(id,'source',p.source||'','Source',(this.dynamicSources||[]).map(s=>({value:s.id,label:s.label})));
                h+=this._inspectorInput(id,'fallback',p.fallback||'','Fallback');
            } else if(t==='posts_list'){
                h+=this._inspectorInput(id,'count',String(p.count||5),'Count','number');
                h+=this._inspectorSelect(id,'type',p.type||'post','Content Type',[{value:'post',label:'Posts'},{value:'page',label:'Pages'}]);
            } else if(t==='quote'){
                h+=this._inspectorTextarea(id,'text',p.text||'','Quote Text',3);
                h+=this._inspectorInput(id,'author',p.author||'','Author');
            } else if(t==='list'){
                h+=this._inspectorTextarea(id,'items',p.items||'','Items (one per line)',5);
                h+='<div><label class="flex items-center gap-2 text-[10px] font-semibold text-slate-400 uppercase tracking-wider cursor-pointer"><input type="checkbox" class="rounded border-slate-600 bg-slate-900 text-violet-500" '+(p.ordered?'checked':'')+' onchange="this.dispatchEvent(new CustomEvent(\'node-prop\',{bubbles:true,detail:{id:\''+id+'\',key:\'ordered\',val:this.checked}}))">Ordered list</label></div>';
            } else if(t==='gallery'){
                h+=this._inspectorSelect(id,'columns',String(p.columns||3),'Columns',[{value:'2',label:'2 Columns'},{value:'3',label:'3 Columns'},{value:'4',label:'4 Columns'}]);
            } else if(t==='embed'){
                h+=this._inspectorInput(id,'url',p.url||'','Embed URL');
                h+=this._inspectorSelect(id,'type',p.type||'youtube','Type',[{value:'youtube',label:'YouTube'},{value:'vimeo',label:'Vimeo'},{value:'iframe',label:'iFrame'}]);
            } else if(t==='html'){
                h+=this._inspectorTextarea(id,'code',p.code||'','HTML Code',8);
            } else if(t==='section'||t==='columns'||t==='container'){
                const layout=p.layout||'flex';const isGrid=layout==='grid';
                h+='<div class="mb-3"><label class="block text-[10px] font-semibold text-slate-400 uppercase tracking-wider mb-1.5">Layout Mode</label><div class="flex gap-1">';
                h+='<button type="button" class="flex-1 px-3 py-2 text-xs font-semibold rounded-lg border transition-colors '+((!isGrid)?'border-violet-400 bg-violet-500/20 text-violet-200':'border-slate-700 text-slate-400 hover:bg-slate-800')+'" onclick="this.dispatchEvent(new CustomEvent(\'node-prop\',{bubbles:true,detail:{id:\''+id+'\',key:\'layout\',val:\'flex\'}}))">Flexbox</button>';
                h+='<button type="button" class="flex-1 px-3 py-2 text-xs font-semibold rounded-lg border transition-colors '+(isGrid?'border-blue-400 bg-blue-500/20 text-blue-200':'border-slate-700 text-slate-400 hover:bg-slate-800')+'" onclick="this.dispatchEvent(new CustomEvent(\'node-prop\',{bubbles:true,detail:{id:\''+id+'\',key:\'layout\',val:\'grid\'}}))">Grid</button>';
                h+='</div></div>';
                if(!isGrid){
                    h+=this._inspectorSelect(id,'direction',p.direction||'column','Direction',[{value:'row',label:'Row \u2192'},{value:'column',label:'Column \u2193'},{value:'row-reverse',label:'Row Reverse \u2190'},{value:'column-reverse',label:'Column Reverse \u2191'}]);
                    h+=this._inspectorSelect(id,'justify',p.justify||'','Justify Content',[{value:'',label:'Default'},{value:'flex-start',label:'Start'},{value:'center',label:'Center'},{value:'flex-end',label:'End'},{value:'space-between',label:'Space Between'},{value:'space-around',label:'Space Around'},{value:'space-evenly',label:'Space Evenly'}]);
                    h+=this._inspectorSelect(id,'align',p.align||'','Align Items',[{value:'',label:'Default'},{value:'flex-start',label:'Start'},{value:'center',label:'Center'},{value:'flex-end',label:'End'},{value:'stretch',label:'Stretch'},{value:'baseline',label:'Baseline'}]);
                    h+=this._inspectorSelect(id,'wrap',p.wrap||'','Wrap',[{value:'',label:'No Wrap'},{value:'wrap',label:'Wrap'},{value:'wrap-reverse',label:'Wrap Reverse'}]);
                } else {
                    h+=this._inspectorInput(id,'grid_columns',String(p.grid_columns||2),'Columns','number');
                    h+=this._inspectorInput(id,'grid_rows',p.grid_rows||'','Rows (blank=auto)');
                    h+=this._inspectorSelect(id,'justify_items',p.justify_items||'','Justify Items',[{value:'',label:'Default'},{value:'start',label:'Start'},{value:'center',label:'Center'},{value:'end',label:'End'},{value:'stretch',label:'Stretch'}]);
                    h+=this._inspectorSelect(id,'align_items',p.align_items||'','Align Items',[{value:'',label:'Default'},{value:'start',label:'Start'},{value:'center',label:'Center'},{value:'end',label:'End'},{value:'stretch',label:'Stretch'}]);
                }
                h+=this._inspectorInput(id,'gap',p.gap||'16px','Gap');
                h+=this._inspectorSelect(id,'width_mode',p.width_mode||'boxed','Container Width',[{value:'boxed',label:'Boxed'},{value:'full',label:'Full Width'}]);
                h+=this._inspectorInput(id,'min_height',p.min_height||'','Min Height');
            } else {
                h+='<p class="text-xs text-slate-400">'+this.esc(t)+' widget</p>';
            }
            return h;
        },
        async preview(){if(!this.form.id){this.message={text:'Save first.',type:'error'};return;}try{const r=await fetch(CMS_BASE+'/api/v1/cms/content/'+this.form.id+'/builder/preview',{headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CMS_CSRF}});const d=await r.json();if(!r.ok||!d.ok)throw new Error(d.error||'Preview failed');this.previewHtml=d.data&&d.data.html?d.data.html:'';this.message={text:'Preview ready.',type:'success'};}catch(e){this.message={text:e.message,type:'error'};}},
        applyTemplate(templateId){
            const found=(this.builderTemplates||[]).find(t=>Number(t.id)===Number(templateId));
            if(!found){this.message={text:'Template not found.',type:'error'};return;}
            let parsed=null;
            try{if(found.template_json){parsed=JSON.parse(found.template_json);}}catch(e){this.message={text:'Invalid template.',type:'error'};return;}
            if(!parsed||!parsed.document){this.message={text:'Invalid template.',type:'error'};return;}
            this.pushUndo();this.doc=this.normalizeNode(parsed.document);this.markDirty();this.message={text:'Template applied.',type:'success'};
        },
        async saveAsTemplate(){const name=window.prompt('Template name');if(!name)return;try{const r=await fetch(CMS_BASE+'/api/v1/cms/builder/templates',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CMS_CSRF},body:JSON.stringify({name:name,category:'page',template_json:JSON.stringify(this.buildCanonicalDocument())})});const d=await r.json();if(!r.ok||!d.ok)throw new Error(d.error||'Failed');await this.reloadBuilderAssets();this.message={text:'Template saved.',type:'success'};}catch(e){this.message={text:e.message,type:'error'};}},
        async deleteTemplate(tid){try{const r=await fetch(CMS_BASE+'/api/v1/cms/builder/templates/'+tid+'/delete',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CMS_CSRF},body:JSON.stringify({})});const d=await r.json();if(!r.ok||!d.ok)throw new Error(d.error||'Failed');await this.reloadBuilderAssets();this.message={text:'Deleted.',type:'success'};}catch(e){this.message={text:e.message,type:'error'};}},
        insertReusable(rid){
            const found=(this.reusableSections||[]).find(t=>Number(t.id)===Number(rid));
            if(!found){this.message={text:'Not found.',type:'error'};return;}
            let parsed=null;
            try{if(found.fragment_json){parsed=JSON.parse(found.fragment_json);}}catch(e){}
            if(!parsed||!parsed.document){this.message={text:'Invalid section.',type:'error'};return;}
            this.pushUndo();
            const incoming=Array.isArray(parsed.document.children)?parsed.document.children:[];
            const reId=(n)=>{n.id=this.genId();if(Array.isArray(n.children))n.children.forEach(reId);};
            incoming.forEach(n=>{const copy=this.normalizeNode(JSON.parse(JSON.stringify(n)));reId(copy);this.doc.children.push(copy);});
            this.markDirty();this.message={text:'Section inserted.',type:'success'};
        },
        async saveReusableSection(){const name=window.prompt('Reusable section name');if(!name)return;try{const r=await fetch(CMS_BASE+'/api/v1/cms/builder/reusable-sections',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CMS_CSRF},body:JSON.stringify({name:name,scope:'shared',fragment:this.buildCanonicalDocument()})});const d=await r.json();if(!r.ok||!d.ok)throw new Error(d.error||'Failed');await this.reloadBuilderAssets();this.message={text:'Saved.',type:'success'};}catch(e){this.message={text:e.message,type:'error'};}},
        async deleteReusable(rid){try{const r=await fetch(CMS_BASE+'/api/v1/cms/builder/reusable-sections/'+rid+'/delete',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CMS_CSRF},body:JSON.stringify({})});const d=await r.json();if(!r.ok||!d.ok)throw new Error(d.error||'Failed');await this.reloadBuilderAssets();this.message={text:'Deleted.',type:'success'};}catch(e){this.message={text:e.message,type:'error'};}},
        async autosave(){if(!this.form.id||!this.dirty||this.saving)return;try{const r=await fetch(CMS_BASE+'/api/v1/cms/content/'+this.form.id+'/builder/autosave',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CMS_CSRF},body:JSON.stringify({title:this.form.title,document:this.buildCanonicalDocument()})});const d=await r.json();if(!r.ok||!d.ok)throw new Error(d.error||'Autosave failed');this.dirty=false;this.autosaveStatus='Saved';await this.reloadRevisions();}catch(e){this.autosaveStatus='Autosave failed';}},
        async save(forceStatus){
            this.message={text:'',type:'success'};this.saving=true;
            try{
                const payload={id:this.form.id,title:this.form.title,slug:this.form.slug,excerpt:this.form.excerpt,status:forceStatus||this.form.status,published_at:this.form.published_at,selected_template:this.form.selected_template,builder_page_settings:this.pageSettings};
                let url=this.form.id?CMS_BASE+'/api/v1/cms/content/'+this.form.id+'/builder':CMS_BASE+'/api/v1/cms/page-builder';
                let body=payload;let msg='Saved.';
                if(this.form.id){
                    if((forceStatus||this.form.status)==='published'){url=CMS_BASE+'/api/v1/cms/content/'+this.form.id+'/builder/publish';body={};msg='Published.';}
                    else{body={title:this.form.title,document:this.buildCanonicalDocument()};}
                }
                const r=await fetch(url,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CMS_CSRF},body:JSON.stringify(body)});
                const d=await r.json();if(!r.ok||!d.ok)throw new Error(d.error||'Save failed');
                if(!this.form.id&&d.id){window.location.href=CMS_BASE+'/cms/admin/page-builder/'+d.id;return;}
                if(d.data&&d.data.document_id)this.builderDocumentId=d.data.document_id;
                if((forceStatus||this.form.status)==='published')this.form.status='published';
                this.dirty=false;this.autosaveStatus='Saved';await this.reloadRevisions();
                this.message={text:msg,type:'success'};if(d.slug)this.form.slug=d.slug;
            }catch(e){this.message={text:e.message,type:'error'};}finally{this.saving=false;}
        },
        async restoreRevision(revId){if(!this.form.id){this.message={text:'Save first.',type:'error'};return;}try{const r=await fetch(CMS_BASE+'/api/v1/cms/content/'+this.form.id+'/builder/revisions/'+revId+'/restore',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CMS_CSRF},body:JSON.stringify({})});const d=await r.json();if(!r.ok||!d.ok)throw new Error(d.error||'Failed');await this.reloadCanonicalDocument();this.message={text:'Revision restored.',type:'success'};}catch(e){this.message={text:e.message,type:'error'};}},
        async reloadCanonicalDocument(){if(!this.form.id)return;try{const r=await fetch(CMS_BASE+'/api/v1/cms/content/'+this.form.id+'/builder',{headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CMS_CSRF}});const d=await r.json();if(r.ok&&d.ok&&d.data&&d.data.document){this.doc=this.normalizeNode(d.data.document.document||d.data.document);this.builderDocumentId=d.data.document_id||this.builderDocumentId;}}catch(e){}await this.reloadRevisions();},
        async reloadRevisions(){if(!this.form.id)return;try{const r=await fetch(CMS_BASE+'/api/v1/cms/content/'+this.form.id+'/builder/revisions',{headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CMS_CSRF}});const d=await r.json();if(r.ok&&d.ok)this.revisions=Array.isArray(d.data)?d.data:[];}catch(e){}},
        async reloadBuilderAssets(){try{const[a,b]=await Promise.all([fetch(CMS_BASE+'/api/v1/cms/builder/reusable-sections',{headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CMS_CSRF}}),fetch(CMS_BASE+'/api/v1/cms/builder/templates',{headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CMS_CSRF}})]);const ad=await a.json();const bd=await b.json();if(a.ok&&ad.ok)this.reusableSections=Array.isArray(ad.data)?ad.data:[];if(b.ok&&bd.ok)this.builderTemplates=Array.isArray(bd.data)?bd.data:[];}catch(e){}},
        async init(){
            this.doc=this.normalizeNode(this.doc||defaultDoc) || defaultDoc;
            if(!Array.isArray(this.doc.children))this.doc.children=[];
            this._nodeCounter=this.countNodes(this.doc.children);
            this.selectedNodeId=this.doc.children.length>0?this.doc.children[0].id:null;
            this.$el.addEventListener('node-prop',(e)=>{const{id,key,val}=e.detail;const node=this.findNode(this.doc.children,id);if(node){if(!node.props)node.props={};node.props[key]=val;this.markDirty();}});
            document.addEventListener('builder-insert',(e)=>{this.insertNode(e.detail);});
            document.addEventListener('builder-section',(e)=>{this.insertSectionLayout(e.detail);});
            document.addEventListener('builder-pattern',(e)=>{this.insertPattern(e.detail);});
            document.addEventListener('builder-select',(e)=>{this.selectNode(e.detail);});
            document.addEventListener('builder-apply-tpl',(e)=>{this.applyTemplate(e.detail);});
            document.addEventListener('builder-del-tpl',(e)=>{this.deleteTemplate(e.detail);});
            document.addEventListener('builder-save-tpl',()=>{this.saveAsTemplate();});
            document.addEventListener('builder-insert-reusable',(e)=>{this.insertReusable(e.detail);});
            document.addEventListener('builder-del-reusable',(e)=>{this.deleteReusable(e.detail);});
            document.addEventListener('builder-save-reusable',()=>{this.saveReusableSection();});
            document.addEventListener('builder-set-form',(e)=>{this.form[e.detail.key]=e.detail.val;this.markDirty();});
            document.addEventListener('widget-search',(e)=>{this.widgetSearch=e.detail;});
            this.reloadBuilderAssets();this.reloadRevisions();
            window.addEventListener('beforeunload',(e)=>{if(!this.dirty)return;e.preventDefault();e.returnValue='';});
            this.autosaveTimer=window.setInterval(()=>this.autosave(),15000);
            window.addEventListener('keydown',(e)=>{if((e.ctrlKey||e.metaKey)&&e.key==='z'){e.preventDefault();if(e.shiftKey)this.redo();else this.undo();}});
        }
    };
}
