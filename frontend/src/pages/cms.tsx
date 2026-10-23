import { useEffect, useState } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  ArrowDown, ArrowUp, Check, Eye, History, Image as ImageIcon, Plus,
  RotateCcw, Search, Trash2, Upload,
} from 'lucide-react'
import { useAuth } from '@/auth/AuthProvider'
import { api, errorMessage, type Paginated } from '@/lib/api'
import {
  formatCurrency,
  type MediaAssetItem, type NavMenuItem, type NavMenuRecord, type OfferItem,
  type PageItem, type PageSectionItem, type PublicBootstrap, type RoomContentItem, type SectionType,
  type TestimonialItem,
} from '@/lib/cms'
import { formatDateTime } from '@/lib/utils'
import {
  Alert, Badge, Button, Card, CardHeader, ConfirmDialog, DataTable, EmptyState,
  ErrorState, FormField, Input, Modal, PageHeader, Select, Spinner, Textarea,
  type Column,
} from '@/components/ui'
import { PageBanner, PageSectionRenderer, SafeHtml } from '@/pages/public'

const statusTone: Record<string, 'success' | 'warning' | 'neutral'> = {
  published: 'success',
  draft: 'warning',
  archived: 'neutral',
}

/* ===================== Reusable Media Picker ===================== */
export function MediaPickerField({ label, value, onChange, disabled }: {
  label: string
  value: number | null
  onChange: (id: number | null, asset?: MediaAssetItem | null) => void
  disabled?: boolean
}) {
  const [open, setOpen] = useState(false)
  const q = useQuery({
    queryKey: ['admin', 'media', 'picker'],
    enabled: open || value !== null,
    queryFn: async () => (await api.get<Paginated<MediaAssetItem>>('/admin/media', { params: { per_page: 60 } })).data.data,
  })
  const selected = q.data?.find((m) => m.id === value) ?? null

  return (
    <div>
      <span className="mb-1 block text-sm font-medium text-slate-700">{label}</span>
      <div className="flex items-center gap-3 rounded-md border border-slate-200 bg-slate-50 p-2.5">
        {selected ? (
          <>
            <img src={selected.url} alt={selected.alt_text} className="h-12 w-16 rounded object-cover" />
            <div className="min-w-0 flex-1 text-xs">
              <p className="truncate font-medium text-slate-800">{selected.title || selected.original_name}</p>
              <p className="truncate text-slate-500">Alt: {selected.alt_text}</p>
            </div>
          </>
        ) : (
          <span className="flex-1 text-xs text-slate-500">{value ? `Media #${value}` : 'No image selected'}</span>
        )}
        {!disabled && (
          <div className="flex gap-1.5">
            <Button type="button" size="sm" variant="secondary" onClick={() => setOpen(true)}>
              <ImageIcon className="h-3.5 w-3.5" /> Choose
            </Button>
            {value && (
              <Button type="button" size="sm" variant="ghost" onClick={() => onChange(null, null)}>Clear</Button>
            )}
          </div>
        )}
      </div>

      <Modal open={open} onOpenChange={setOpen} title="Select Media Asset" description="Choose a public image from the Media Library." wide>
        {q.isLoading ? <Spinner /> : (
          <div className="grid max-h-[60vh] gap-3 overflow-y-auto sm:grid-cols-3">
            {q.data?.filter((m) => m.is_public && m.mime_type.startsWith('image/')).map((m) => (
              <button
                key={m.id}
                type="button"
                onClick={() => { onChange(m.id, m); setOpen(false) }}
                className={`flex flex-col overflow-hidden rounded-lg border text-left transition ${
                  m.id === value ? 'border-brand-700 ring-2 ring-brand-500' : 'border-slate-200 hover:border-brand-500'
                }`}
              >
                <img src={m.url} alt={m.alt_text} className="h-28 w-full object-cover" />
                <div className="p-2 text-xs">
                  <p className="truncate font-medium text-slate-800">{m.title || m.original_name}</p>
                  <p className="truncate text-slate-500">{m.alt_text}</p>
                </div>
              </button>
            ))}
          </div>
        )}
      </Modal>
    </div>
  )
}

/* ===================== 1. CMS Pages, Sections, Revisions & Preview ===================== */
export function CmsPagesPage() {
  const { can } = useAuth()
  const qc = useQueryClient()
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [editingId, setEditingId] = useState<number | 'new' | null>(null)
  const [deleting, setDeleting] = useState<PageItem | null>(null)

  const q = useQuery({
    queryKey: ['admin', 'pages', search, status],
    queryFn: async () => (await api.get<Paginated<PageItem>>('/admin/pages', { params: { search: search || undefined, status: status || undefined, per_page: 50 } })).data,
    placeholderData: keepPreviousData,
  })

  const statusMut = useMutation({
    mutationFn: async ({ id, action }: { id: number; action: 'publish' | 'unpublish' | 'archive' }) =>
      api.post(`/admin/pages/${id}/${action}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin', 'pages'] })
      qc.invalidateQueries({ queryKey: ['public'] })
    },
  })

  const delMut = useMutation({
    mutationFn: async (id: number) => api.delete(`/admin/pages/${id}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin', 'pages'] })
      qc.invalidateQueries({ queryKey: ['public'] })
      setDeleting(null)
    },
  })

  const columns: Column<PageItem>[] = [
    {
      key: 'title',
      header: 'Page',
      cell: (p) => (
        <div>
          <div className="flex items-center gap-2">
            <span className="font-medium text-slate-900">{p.title}</span>
            {p.is_system && <Badge tone="brand">Core</Badge>}
          </div>
          <p className="text-xs text-slate-500">/{p.slug === 'home' ? '' : p.slug} · Template: {p.template}</p>
        </div>
      ),
    },
    { key: 'status', header: 'Status', cell: (p) => <Badge tone={statusTone[p.status] ?? 'neutral'}>{p.status}</Badge> },
    { key: 'meta', header: 'Structure', cell: (p) => <span className="text-xs text-slate-600">{p.sections_count ?? 0} sections · {p.revisions_count ?? 0} revisions</span> },
    { key: 'published', header: 'Published', cell: (p) => formatDateTime(p.published_at) },
    {
      key: 'actions',
      header: '',
      className: 'text-right',
      cell: (p) => (
        <div className="flex justify-end gap-1.5">
          <Button size="sm" variant="secondary" onClick={() => setEditingId(p.id)}>
            {can('website.edit') ? 'Edit / Preview' : 'Inspect'}
          </Button>
          {can('website.publish') && p.status !== 'published' && (
            <Button size="sm" onClick={() => statusMut.mutate({ id: p.id, action: 'publish' })}>Publish</Button>
          )}
          {can('website.publish') && p.status === 'published' && (
            <Button size="sm" variant="secondary" onClick={() => statusMut.mutate({ id: p.id, action: 'unpublish' })}>Unpublish</Button>
          )}
          {can('website.delete') && !p.is_system && (
            <Button size="sm" variant="ghost" aria-label={`Delete ${p.title}`} onClick={() => setDeleting(p)}>
              <Trash2 className="h-4 w-4 text-red-600" />
            </Button>
          )}
        </div>
      ),
    },
  ]

  return (
    <>
      <PageHeader
        title="Website Pages"
        description="Manage public website pages, structured content sections, SEO metadata, and revision history."
        actions={can('website.create') && <Button onClick={() => setEditingId('new')}><Plus className="h-4 w-4" /> New Page</Button>}
      />
      <Card>
        <div className="flex flex-wrap gap-3 border-b border-slate-200 p-4">
          <div className="relative max-w-xs flex-1">
            <Search className="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-400" aria-hidden />
            <Input aria-label="Search pages" placeholder="Search title or slug…" className="pl-9" value={search} onChange={(e) => setSearch(e.target.value)} />
          </div>
          <Select aria-label="Filter by status" className="max-w-[11rem]" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">All statuses</option>
            <option value="published">Published</option>
            <option value="draft">Draft</option>
            <option value="archived">Archived</option>
          </Select>
        </div>
        <DataTable
          columns={columns}
          rows={q.data?.data}
          rowKey={(p) => p.id}
          isLoading={q.isLoading}
          error={q.isError ? errorMessage(q.error) : null}
          onRetry={() => q.refetch()}
        />
      </Card>

      {editingId !== null && (
        <PageEditorModal
          pageId={editingId === 'new' ? null : editingId}
          open={editingId !== null}
          onClose={() => setEditingId(null)}
        />
      )}

      <ConfirmDialog
        open={!!deleting}
        onClose={() => setDeleting(null)}
        onConfirm={() => deleting && delMut.mutate(deleting.id)}
        loading={delMut.isPending}
        title="Delete page"
        description={`Are you sure you want to permanently delete "${deleting?.title}"?`}
        confirmLabel="Delete page"
      />
    </>
  )
}

function PageEditorModal({ pageId, open, onClose }: { pageId: number | null; open: boolean; onClose: () => void }) {
  const { can } = useAuth()
  const qc = useQueryClient()
  const creating = pageId === null
  const editable = creating ? can('website.create') : can('website.edit')
  const canPublish = can('website.publish')

  const [tab, setTab] = useState<'content' | 'sections' | 'seo' | 'revisions' | 'preview'>('content')
  const [error, setError] = useState<string | null>(null)
  const [msg, setMsg] = useState<string | null>(null)

  const [title, setTitle] = useState('')
  const [slug, setSlug] = useState('')
  const [subtitle, setSubtitle] = useState('')
  const [excerpt, setExcerpt] = useState('')
  const [body, setBody] = useState('')
  const [template, setTemplate] = useState('standard')
  const [status, setStatus] = useState<'draft' | 'published' | 'archived'>('draft')
  const [featuredMediaId, setFeaturedMediaId] = useState<number | null>(null)
  const [seoTitle, setSeoTitle] = useState('')
  const [seoDesc, setSeoDesc] = useState('')
  const [canonicalUrl, setCanonicalUrl] = useState('')
  const [ogImageId, setOgImageId] = useState<number | null>(null)
  const [noindex, setNoindex] = useState(false)
  const [revisionNote, setRevisionNote] = useState('')
  const [sections, setSections] = useState<PageSectionItem[]>([])

  const q = useQuery({
    queryKey: ['admin', 'page', pageId],
    enabled: !creating && open,
    queryFn: async () => (await api.get<{ data: PageItem }>(`/admin/pages/${pageId}`)).data.data,
  })

  useEffect(() => {
    if (creating) {
      // oxlint-disable-next-line react/set-state-in-effect -- This synchronizes the editable draft with the selected page.
      setTitle(''); setSlug(''); setSubtitle(''); setExcerpt(''); setBody('')
      setTemplate('standard'); setStatus('draft'); setFeaturedMediaId(null)
      setSeoTitle(''); setSeoDesc(''); setCanonicalUrl(''); setOgImageId(null); setNoindex(false)
      setRevisionNote(''); setSections([])
    } else if (q.data) {
      const p = q.data
      setTitle(p.title); setSlug(p.slug); setSubtitle(p.subtitle ?? '')
      setExcerpt(p.excerpt ?? ''); setBody(p.body ?? ''); setTemplate(p.template)
      setStatus(p.status); setFeaturedMediaId(p.featured_media_id)
      setSeoTitle(p.seo_title ?? ''); setSeoDesc(p.seo_description ?? '')
      setCanonicalUrl(p.canonical_url ?? ''); setOgImageId(p.og_image_id)
      setNoindex(p.noindex); setRevisionNote('')
      setSections(p.sections ?? [])
    }
  }, [creating, q.data])

  const save = useMutation({
    mutationFn: async () => {
      const payload = {
        title, slug, subtitle: subtitle || null, excerpt: excerpt || null,
        body: body || null, template, status, featured_media_id: featuredMediaId,
        seo_title: seoTitle || null, seo_description: seoDesc || null,
        canonical_url: canonicalUrl || null, og_image_id: ogImageId,
        noindex, revision_note: revisionNote || undefined,
        sections: sections.map((s, idx) => ({ ...s, sort_order: idx })),
      }
      return creating
        ? (await api.post<{ data: PageItem }>('/admin/pages', payload)).data.data
        : (await api.put<{ data: PageItem }>(`/admin/pages/${pageId}`, payload)).data.data
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin', 'pages'] })
      if (pageId) qc.invalidateQueries({ queryKey: ['admin', 'page', pageId] })
      qc.invalidateQueries({ queryKey: ['public'] })
      setMsg('Page saved and revision recorded.')
      setError(null)
      if (creating) onClose()
    },
    onError: (e) => { setError(errorMessage(e)); setMsg(null) },
  })

  const restoreMut = useMutation({
    mutationFn: async (revId: number) =>
      (await api.post<{ data: PageItem }>(`/admin/pages/${pageId}/revisions/${revId}/restore`)).data.data,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin', 'page', pageId] })
      qc.invalidateQueries({ queryKey: ['admin', 'pages'] })
      qc.invalidateQueries({ queryKey: ['public'] })
      setMsg('Revision restored.')
      setTab('content')
    },
    onError: (e) => setError(errorMessage(e)),
  })

  const addSection = (type: SectionType) => {
    setSections((prev) => [
      ...prev,
      {
        type, title: 'New Section', subtitle: null, body: '<p>Section content…</p>',
        media_id: null, cta_label: null, cta_url: null,
        data: type === 'feature_grid' ? { items: [{ title: 'Feature', description: 'Details' }] } : type === 'faq' ? { items: [{ question: 'Question?', answer: 'Answer.' }] } : null,
        is_visible: true, sort_order: prev.length,
      },
    ])
  }

  const moveSection = (index: number, dir: -1 | 1) => {
    setSections((prev) => {
      const next = [...prev]
      const target = index + dir
      if (target < 0 || target >= next.length) return prev
      const [item] = next.splice(index, 1)
      if (item) next.splice(target, 0, item)
      return next
    })
  }

  const updateSection = (index: number, patch: Partial<PageSectionItem>) => {
    setSections((prev) => prev.map((s, i) => (i === index ? { ...s, ...patch } : s)))
  }

  const insertTag = (openTag: string, closeTag: string) => {
    setBody((b) => `${b}${openTag}Text${closeTag}`)
  }

  return (
    <Modal open={open} onOpenChange={(o) => !o && onClose()} title={creating ? 'Create Page' : `Edit Page: ${title}`} wide>
      {!creating && q.isLoading ? <Spinner /> : (
        <div className="space-y-4">
          {error && <Alert variant="error">{error}</Alert>}
          {msg && <Alert variant="success">{msg}</Alert>}

          {/* Tabs */}
          <div className="flex flex-wrap gap-1 border-b border-slate-200 pb-2">
            {([
              ['content', '1. Content'],
              ['sections', `2. Sections (${sections.length})`],
              ['seo', '3. SEO & Social'],
              ['preview', '4. Safe Preview'],
              ...(!creating ? [['revisions', `5. Revisions (${q.data?.revisions?.length ?? 0})`]] : []),
            ] as [typeof tab, string][]).map(([key, label]) => (
              <Button key={key} type="button" size="sm" variant={tab === key ? 'primary' : 'ghost'} onClick={() => setTab(key)}>
                {key === 'preview' && <Eye className="h-3.5 w-3.5" />}
                {key === 'revisions' && <History className="h-3.5 w-3.5" />}
                {label}
              </Button>
            ))}
          </div>

          {tab === 'content' && (
            <div className="space-y-4">
              <div className="grid gap-4 sm:grid-cols-2">
                <FormField id="p-title" label="Page Title">
                  <Input id="p-title" disabled={!editable} value={title} onChange={(e) => {
                    setTitle(e.target.value)
                    if (creating && !slug) setSlug(e.target.value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, ''))
                  }} />
                </FormField>
                <FormField id="p-slug" label="URL Slug" hint={q.data?.is_system ? 'Core page slug is locked' : 'Lowercase letters, numbers, and hyphens'}>
                  <Input id="p-slug" disabled={!editable || !!q.data?.is_system} value={slug} onChange={(e) => setSlug(e.target.value)} />
                </FormField>
                <FormField id="p-subtitle" label="Banner Subtitle">
                  <Input id="p-subtitle" disabled={!editable} value={subtitle} onChange={(e) => setSubtitle(e.target.value)} />
                </FormField>
                <div className="grid grid-cols-2 gap-3">
                  <FormField id="p-template" label="Template">
                    <Select id="p-template" disabled={!editable} value={template} onChange={(e) => setTemplate(e.target.value)}>
                      {['standard', 'home', 'about', 'rooms', 'booking', 'facilities', 'restaurant', 'gallery', 'offers', 'events', 'contact', 'legal'].map((t) => (
                        <option key={t} value={t}>{t}</option>
                      ))}
                    </Select>
                  </FormField>
                  <FormField id="p-status" label="Status">
                    <Select id="p-status" disabled={!editable || !canPublish} value={status} onChange={(e) => setStatus(e.target.value as typeof status)}>
                      <option value="draft">Draft</option>
                      <option value="published">Published</option>
                      <option value="archived">Archived</option>
                    </Select>
                  </FormField>
                </div>
              </div>

              <FormField id="p-excerpt" label="Short Summary / Excerpt">
                <Textarea id="p-excerpt" rows={2} disabled={!editable} value={excerpt} onChange={(e) => setExcerpt(e.target.value)} />
              </FormField>

              <div>
                <div className="mb-1 flex flex-wrap items-center justify-between gap-2">
                  <label htmlFor="p-body" className="text-sm font-medium text-slate-700">Page Body (Sanitized Rich Text / HTML)</label>
                  {editable && (
                    <div className="flex flex-wrap gap-1 text-xs">
                      <Button type="button" size="sm" variant="secondary" onClick={() => insertTag('<p>', '</p>')}>Paragraph</Button>
                      <Button type="button" size="sm" variant="secondary" onClick={() => insertTag('<h2>', '</h2>')}>H2</Button>
                      <Button type="button" size="sm" variant="secondary" onClick={() => insertTag('<strong>', '</strong>')}>Bold</Button>
                      <Button type="button" size="sm" variant="secondary" onClick={() => insertTag('<ul><li>', '</li></ul>')}>List</Button>
                    </div>
                  )}
                </div>
                <Textarea id="p-body" rows={6} disabled={!editable} value={body} onChange={(e) => setBody(e.target.value)} />
                <p className="mt-1 text-xs text-slate-500">Scripts, iframes, event handlers, and unsafe URLs are automatically stripped by the server.</p>
              </div>

              <MediaPickerField label="Featured Banner Image" value={featuredMediaId} onChange={(id) => setFeaturedMediaId(id)} disabled={!editable} />

              <FormField id="p-revnote" label="Revision Note (optional)" hint="Recorded in the revision history when you save.">
                <Input id="p-revnote" disabled={!editable} placeholder="e.g. Updated dining hours" value={revisionNote} onChange={(e) => setRevisionNote(e.target.value)} />
              </FormField>
            </div>
          )}

          {tab === 'sections' && (
            <div className="space-y-4">
              {editable && (
                <div className="flex flex-wrap items-center gap-2 rounded-md bg-slate-50 p-3">
                  <span className="text-xs font-semibold uppercase text-slate-500">Add Section:</span>
                  {(['rich_text', 'image_text', 'feature_grid', 'cta_banner', 'faq', 'notice', 'hero'] as SectionType[]).map((t) => (
                    <Button key={t} type="button" size="sm" variant="secondary" onClick={() => addSection(t)}>
                      <Plus className="h-3.5 w-3.5" /> {t.replace('_', ' ')}
                    </Button>
                  ))}
                </div>
              )}

              {sections.length === 0 ? (
                <EmptyState title="No structured sections on this page" description="Add sections above to build modular content blocks." />
              ) : (
                <div className="space-y-4">
                  {sections.map((sec, idx) => (
                    <Card key={idx} className="p-4">
                      <div className="mb-3 flex items-center justify-between gap-2 border-b border-slate-100 pb-2">
                        <div className="flex items-center gap-2">
                          <Badge tone="brand">#{idx + 1} · {sec.type}</Badge>
                          <label className="flex items-center gap-1.5 text-xs text-slate-600">
                            <input type="checkbox" disabled={!editable} checked={sec.is_visible} onChange={(e) => updateSection(idx, { is_visible: e.target.checked })} />
                            Visible
                          </label>
                        </div>
                        {editable && (
                          <div className="flex gap-1">
                            <Button type="button" size="sm" variant="ghost" disabled={idx === 0} onClick={() => moveSection(idx, -1)} aria-label="Move section up"><ArrowUp className="h-4 w-4" /></Button>
                            <Button type="button" size="sm" variant="ghost" disabled={idx === sections.length - 1} onClick={() => moveSection(idx, 1)} aria-label="Move section down"><ArrowDown className="h-4 w-4" /></Button>
                            <Button type="button" size="sm" variant="ghost" onClick={() => setSections((p) => p.filter((_, i) => i !== idx))} aria-label="Remove section"><Trash2 className="h-4 w-4 text-red-600" /></Button>
                          </div>
                        )}
                      </div>
                      <div className="grid gap-3 sm:grid-cols-2">
                        <FormField id={`s-title-${idx}`} label="Section Title">
                          <Input id={`s-title-${idx}`} disabled={!editable} value={sec.title ?? ''} onChange={(e) => updateSection(idx, { title: e.target.value })} />
                        </FormField>
                        <FormField id={`s-sub-${idx}`} label="Subtitle">
                          <Input id={`s-sub-${idx}`} disabled={!editable} value={sec.subtitle ?? ''} onChange={(e) => updateSection(idx, { subtitle: e.target.value })} />
                        </FormField>
                        <div className="sm:col-span-2">
                          <FormField id={`s-body-${idx}`} label="Body HTML">
                            <Textarea id={`s-body-${idx}`} rows={3} disabled={!editable} value={sec.body ?? ''} onChange={(e) => updateSection(idx, { body: e.target.value })} />
                          </FormField>
                        </div>
                        {['hero', 'image_text', 'cta_banner'].includes(sec.type) && (
                          <>
                            <FormField id={`s-ctal-${idx}`} label="Button Label">
                              <Input id={`s-ctal-${idx}`} disabled={!editable} value={sec.cta_label ?? ''} onChange={(e) => updateSection(idx, { cta_label: e.target.value })} />
                            </FormField>
                            <FormField id={`s-ctau-${idx}`} label="Button URL">
                              <Input id={`s-ctau-${idx}`} disabled={!editable} value={sec.cta_url ?? ''} onChange={(e) => updateSection(idx, { cta_url: e.target.value })} />
                            </FormField>
                          </>
                        )}
                        {['hero', 'image_text'].includes(sec.type) && (
                          <div className="sm:col-span-2">
                            <MediaPickerField label="Section Image" value={sec.media_id} onChange={(id, asset) => updateSection(idx, { media_id: id, media: asset })} disabled={!editable} />
                          </div>
                        )}
                      </div>
                    </Card>
                  ))}
                </div>
              )}
            </div>
          )}

          {tab === 'seo' && (
            <div className="space-y-4">
              <FormField id="p-seot" label="SEO Title" hint="Recommended up to 60–70 characters. Defaults to Page Title | Mai Chitta Hotel.">
                <Input id="p-seot" disabled={!editable} maxLength={160} value={seoTitle} onChange={(e) => setSeoTitle(e.target.value)} />
              </FormField>
              <FormField id="p-seod" label="Meta Description" hint="Recommended up to 160 characters.">
                <Textarea id="p-seod" rows={3} disabled={!editable} maxLength={320} value={seoDesc} onChange={(e) => setSeoDesc(e.target.value)} />
              </FormField>
              <FormField id="p-canon" label="Canonical URL (optional)">
                <Input id="p-canon" disabled={!editable} placeholder="https://..." value={canonicalUrl} onChange={(e) => setCanonicalUrl(e.target.value)} />
              </FormField>
              <MediaPickerField label="Social Sharing (OpenGraph) Image" value={ogImageId} onChange={(id) => setOgImageId(id)} disabled={!editable} />
              <label className="flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" disabled={!editable} checked={noindex} onChange={(e) => setNoindex(e.target.checked)} />
                Discourage search engines from indexing this page (<code>noindex,nofollow</code>)
              </label>
            </div>
          )}

          {tab === 'preview' && (
            <div className="overflow-hidden rounded-xl border border-slate-200 bg-slate-50">
              <div className="bg-amber-100 px-4 py-2 text-xs font-medium text-amber-900">
                Safe Preview Mode — Showing current editor state ({status}). No scripts can execute.
              </div>
              <PageBanner title={title || 'Untitled Page'} subtitle={subtitle} media={q.data?.featured_media} />
              <div className="space-y-6 p-6">
                {body && <Card className="p-6"><SafeHtml html={body} /></Card>}
                {sections.map((sec, idx) => <PageSectionRenderer key={idx} section={sec} />)}
              </div>
            </div>
          )}

          {tab === 'revisions' && (
            <div className="space-y-3">
              {!q.data?.revisions?.length ? (
                <EmptyState title="No revisions recorded yet" />
              ) : (
                q.data.revisions.map((rev) => (
                  <Card key={rev.id} className="flex flex-wrap items-center justify-between gap-4 p-4">
                    <div>
                      <div className="flex items-center gap-2">
                        <Badge tone="brand">v{rev.version}</Badge>
                        <span className="font-medium text-slate-900">{rev.note || 'Snapshot'}</span>
                      </div>
                      <p className="mt-1 text-xs text-slate-500">
                        {formatDateTime(rev.created_at)} · By {rev.author?.name ?? 'System'} · Title: “{rev.snapshot.title}” ({rev.snapshot.sections?.length ?? 0} sections)
                      </p>
                    </div>
                    {editable && (
                      <Button
                        type="button"
                        size="sm"
                        variant="secondary"
                        loading={restoreMut.isPending}
                        onClick={() => restoreMut.mutate(rev.id)}
                      >
                        <RotateCcw className="h-3.5 w-3.5" /> Restore v{rev.version}
                      </Button>
                    )}
                  </Card>
                ))
              )}
            </div>
          )}

          <div className="flex justify-end gap-2 border-t border-slate-200 pt-4">
            <Button type="button" variant="secondary" onClick={onClose}>Close</Button>
            {editable && (
              <Button type="button" loading={save.isPending} onClick={() => save.mutate()}>
                <Check className="h-4 w-4" /> {creating ? 'Create Page' : 'Save Changes'}
              </Button>
            )}
          </div>
        </div>
      )}
    </Modal>
  )
}

/* ===================== 2. Media Library ===================== */
export function CmsMediaPage() {
  const { can } = useAuth()
  const qc = useQueryClient()
  const [category, setCategory] = useState('')
  const [uploading, setUploading] = useState(false)
  const [editing, setEditing] = useState<MediaAssetItem | null>(null)
  const [deleting, setDeleting] = useState<MediaAssetItem | null>(null)

  const [file, setFile] = useState<File | null>(null)
  const [altText, setAltText] = useState('')
  const [title, setTitle] = useState('')
  const [caption, setCaption] = useState('')
  const [upCat, setUpCat] = useState('general')
  const [isPublic, setIsPublic] = useState(true)
  const [inGallery, setInGallery] = useState(false)
  const [upErr, setUpErr] = useState<string | null>(null)

  const q = useQuery({
    queryKey: ['admin', 'media', category],
    queryFn: async () => (await api.get<Paginated<MediaAssetItem>>('/admin/media', { params: { category: category || undefined, per_page: 48 } })).data,
  })

  const uploadMut = useMutation({
    mutationFn: async () => {
      if (!file) throw new Error('Select a file')
      const fd = new FormData()
      fd.append('file', file)
      fd.append('alt_text', altText)
      if (title) fd.append('title', title)
      if (caption) fd.append('caption', caption)
      fd.append('category', upCat)
      fd.append('is_public', isPublic ? '1' : '0')
      fd.append('in_gallery', inGallery ? '1' : '0')
      return (await api.post('/admin/media', fd)).data
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin', 'media'] })
      qc.invalidateQueries({ queryKey: ['public'] })
      setUploading(false)
      setFile(null); setAltText(''); setTitle(''); setCaption(''); setUpErr(null)
    },
    onError: (e) => setUpErr(errorMessage(e)),
  })

  const updateMut = useMutation({
    mutationFn: async (m: MediaAssetItem) =>
      api.put(`/admin/media/${m.id}`, {
        alt_text: m.alt_text, title: m.title, caption: m.caption,
        category: m.category, in_gallery: m.in_gallery, sort_order: m.sort_order,
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin', 'media'] })
      qc.invalidateQueries({ queryKey: ['public'] })
      setEditing(null)
    },
  })

  const delMut = useMutation({
    mutationFn: async (id: number) => api.delete(`/admin/media/${id}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin', 'media'] })
      qc.invalidateQueries({ queryKey: ['public'] })
      setDeleting(null)
    },
  })

  return (
    <>
      <PageHeader
        title="Media Library"
        description="Manage hotel images, gallery photographs, and private documents. Allowed uploads: JPG, PNG, WEBP, PDF up to 5 MB."
        actions={can('media.upload') && <Button onClick={() => setUploading(true)}><Upload className="h-4 w-4" /> Upload File</Button>}
      />
      <Card>
        <div className="flex flex-wrap gap-3 border-b border-slate-200 p-4">
          <Select aria-label="Filter media by category" className="max-w-xs" value={category} onChange={(e) => setCategory(e.target.value)}>
            <option value="">All categories</option>
            {['general', 'rooms', 'restaurant', 'facilities', 'events', 'gallery', 'branding'].map((c) => (
              <option key={c} value={c}>{c}</option>
            ))}
          </Select>
        </div>

        {q.isLoading ? <Spinner /> : q.isError ? <ErrorState message={errorMessage(q.error)} onRetry={() => q.refetch()} /> : (
          !q.data?.data.length ? <EmptyState title="No media files found" /> : (
            <div className="grid gap-4 p-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
              {q.data.data.map((m) => (
                <Card key={m.id} className="flex flex-col overflow-hidden">
                  {m.mime_type.startsWith('image/') ? (
                    <img src={m.url} alt={m.alt_text} className="h-40 w-full bg-slate-100 object-cover" loading="lazy" />
                  ) : (
                    <div className="flex h-40 items-center justify-center bg-slate-100 text-xs font-semibold uppercase text-slate-600">{m.extension} Document</div>
                  )}
                  <div className="flex flex-1 flex-col p-3 text-xs">
                    <div className="mb-1 flex flex-wrap gap-1">
                      <Badge tone={m.is_public ? 'success' : 'warning'}>{m.is_public ? 'Public' : 'Private'}</Badge>
                      {m.in_gallery && <Badge tone="brand">In Gallery</Badge>}
                      <Badge>{m.category}</Badge>
                    </div>
                    <p className="truncate font-semibold text-slate-900">{m.title || m.original_name}</p>
                    <p className="mt-0.5 truncate text-slate-500">Alt: {m.alt_text}</p>
                    <div className="mt-3 flex justify-end gap-1 pt-2">
                      {can('media.upload') && <Button size="sm" variant="secondary" onClick={() => setEditing(m)}>Edit</Button>}
                      {can('media.delete') && (
                        <Button size="sm" variant="ghost" aria-label={`Delete ${m.original_name}`} onClick={() => setDeleting(m)}>
                          <Trash2 className="h-4 w-4 text-red-600" />
                        </Button>
                      )}
                    </div>
                  </div>
                </Card>
              ))}
            </div>
          )
        )}
      </Card>

      {/* Upload Modal */}
      <Modal open={uploading} onOpenChange={setUploading} title="Upload Media">
        <form onSubmit={(e) => { e.preventDefault(); uploadMut.mutate() }} className="space-y-4">
          {upErr && <Alert variant="error">{upErr}</Alert>}
          <FormField id="m-file" label="File (JPG, PNG, WEBP, PDF · max 5 MB)">
            <Input id="m-file" type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
          </FormField>
          <FormField id="m-alt" label="Alternative Text (required for accessibility)">
            <Input id="m-alt" required value={altText} onChange={(e) => setAltText(e.target.value)} />
          </FormField>
          <FormField id="m-title" label="Title">
            <Input id="m-title" value={title} onChange={(e) => setTitle(e.target.value)} />
          </FormField>
          <FormField id="m-cap" label="Caption">
            <Input id="m-cap" value={caption} onChange={(e) => setCaption(e.target.value)} />
          </FormField>
          <FormField id="m-cat" label="Category">
            <Select id="m-cat" value={upCat} onChange={(e) => setUpCat(e.target.value)}>
              {['general', 'rooms', 'restaurant', 'facilities', 'events', 'gallery', 'branding'].map((c) => <option key={c} value={c}>{c}</option>)}
            </Select>
          </FormField>
          <div className="space-y-2 text-sm">
            <label className="flex items-center gap-2"><input type="checkbox" checked={isPublic} onChange={(e) => setIsPublic(e.target.checked)} /> Publicly accessible on website</label>
            {isPublic && <label className="flex items-center gap-2"><input type="checkbox" checked={inGallery} onChange={(e) => setInGallery(e.target.checked)} /> Feature in public Photo Gallery</label>}
          </div>
          <div className="flex justify-end gap-2">
            <Button type="button" variant="secondary" onClick={() => setUploading(false)}>Cancel</Button>
            <Button type="submit" loading={uploadMut.isPending} disabled={!file || !altText.trim()}>Upload</Button>
          </div>
        </form>
      </Modal>

      {/* Edit Media Modal */}
      <Modal open={!!editing} onOpenChange={(o) => !o && setEditing(null)} title="Edit Media Details">
        {editing && (
          <form onSubmit={(e) => { e.preventDefault(); updateMut.mutate(editing) }} className="space-y-4">
            <FormField id="em-alt" label="Alternative Text">
              <Input id="em-alt" required value={editing.alt_text} onChange={(e) => setEditing({ ...editing, alt_text: e.target.value })} />
            </FormField>
            <FormField id="em-title" label="Title">
              <Input id="em-title" value={editing.title ?? ''} onChange={(e) => setEditing({ ...editing, title: e.target.value })} />
            </FormField>
            <FormField id="em-cap" label="Caption">
              <Input id="em-cap" value={editing.caption ?? ''} onChange={(e) => setEditing({ ...editing, caption: e.target.value })} />
            </FormField>
            <FormField id="em-cat" label="Category">
              <Select id="em-cat" value={editing.category} onChange={(e) => setEditing({ ...editing, category: e.target.value as MediaAssetItem['category'] })}>
                {['general', 'rooms', 'restaurant', 'facilities', 'events', 'gallery', 'branding'].map((c) => <option key={c} value={c}>{c}</option>)}
              </Select>
            </FormField>
            {editing.is_public && (
              <label className="flex items-center gap-2 text-sm">
                <input type="checkbox" checked={editing.in_gallery} onChange={(e) => setEditing({ ...editing, in_gallery: e.target.checked })} />
                Display in public Photo Gallery
              </label>
            )}
            <div className="flex justify-end gap-2">
              <Button type="button" variant="secondary" onClick={() => setEditing(null)}>Cancel</Button>
              <Button type="submit" loading={updateMut.isPending}>Save</Button>
            </div>
          </form>
        )}
      </Modal>

      <ConfirmDialog
        open={!!deleting}
        onClose={() => setDeleting(null)}
        onConfirm={() => deleting && delMut.mutate(deleting.id)}
        loading={delMut.isPending}
        title="Delete media file"
        description={`Delete "${deleting?.original_name}" permanently from storage?`}
        confirmLabel="Delete file"
      />
    </>
  )
}

/* ===================== 3. Navigation Menus ===================== */
export function CmsMenusPage() {
  const { can } = useAuth()
  const qc = useQueryClient()
  const editable = can('website.edit')
  const q = useQuery({
    queryKey: ['admin', 'menus'],
    queryFn: async () => (await api.get<{ data: NavMenuRecord[] }>('/admin/menus')).data.data,
  })
  const [activeSlug, setActiveSlug] = useState<'header' | 'footer'>('header')
  const [items, setItems] = useState<NavMenuItem[]>([])
  const [msg, setMsg] = useState<{ t: 'success' | 'error'; m: string } | null>(null)

  const currentMenu = q.data?.find((m) => m.slug === activeSlug)
  useEffect(() => {
    if (currentMenu) {
      // oxlint-disable-next-line react/set-state-in-effect -- This synchronizes the editable draft with the selected menu.
      setItems(currentMenu.items)
      setMsg(null)
    }
  }, [currentMenu])

  const saveMut = useMutation({
    mutationFn: async () =>
      api.put(`/admin/menus/${activeSlug}`, {
        items: items.map((it, idx) => ({ ...it, sort_order: idx })),
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin', 'menus'] })
      qc.invalidateQueries({ queryKey: ['public'] })
      setMsg({ t: 'success', m: 'Navigation menu saved.' })
    },
    onError: (e) => setMsg({ t: 'error', m: errorMessage(e) }),
  })

  const move = (idx: number, dir: -1 | 1) => {
    setItems((prev) => {
      const next = [...prev]
      const target = idx + dir
      if (target < 0 || target >= next.length) return prev
      const [it] = next.splice(idx, 1)
      if (it) next.splice(target, 0, it)
      return next
    })
  }

  if (q.isLoading) return <Spinner />
  if (q.isError) return <ErrorState message={errorMessage(q.error)} onRetry={() => q.refetch()} />

  return (
    <>
      <PageHeader
        title="Navigation Menus"
        description="Configure the primary header navigation bar and footer links on the public website."
        actions={editable && <Button loading={saveMut.isPending} onClick={() => saveMut.mutate()}>Save Menu</Button>}
      />
      {msg && <div className="mb-4"><Alert variant={msg.t}>{msg.m}</Alert></div>}
      <div className="mb-4 flex gap-2">
        <Button variant={activeSlug === 'header' ? 'primary' : 'secondary'} onClick={() => setActiveSlug('header')}>Header Navigation</Button>
        <Button variant={activeSlug === 'footer' ? 'primary' : 'secondary'} onClick={() => setActiveSlug('footer')}>Footer Navigation</Button>
      </div>

      <Card>
        <CardHeader
          title={currentMenu?.name ?? 'Menu'}
          description={currentMenu?.description ?? undefined}
          actions={editable && (
            <Button size="sm" variant="secondary" onClick={() => setItems((p) => [...p, { label: 'New Link', url: '/', target: '_self', is_visible: true, sort_order: p.length }])}>
              <Plus className="h-4 w-4" /> Add Link
            </Button>
          )}
        />
        <div className="divide-y divide-slate-100 p-4">
          {items.map((it, idx) => (
            <div key={idx} className="flex flex-wrap items-center gap-3 py-2.5">
              <span className="w-6 text-xs font-semibold text-slate-400">#{idx + 1}</span>
              <Input aria-label="Menu label" disabled={!editable} className="max-w-xs" placeholder="Label" value={it.label} onChange={(e) => setItems((p) => p.map((x, i) => (i === idx ? { ...x, label: e.target.value } : x)))} />
              <Input aria-label="Menu URL" disabled={!editable} className="max-w-xs" placeholder="/path or https://..." value={it.url} onChange={(e) => setItems((p) => p.map((x, i) => (i === idx ? { ...x, url: e.target.value } : x)))} />
              <label className="flex items-center gap-1.5 text-xs text-slate-600">
                <input type="checkbox" disabled={!editable} checked={it.is_visible} onChange={(e) => setItems((p) => p.map((x, i) => (i === idx ? { ...x, is_visible: e.target.checked } : x)))} />
                Visible
              </label>
              {editable && (
                <div className="ml-auto flex gap-1">
                  <Button type="button" size="sm" variant="ghost" disabled={idx === 0} onClick={() => move(idx, -1)} aria-label="Move up"><ArrowUp className="h-4 w-4" /></Button>
                  <Button type="button" size="sm" variant="ghost" disabled={idx === items.length - 1} onClick={() => move(idx, 1)} aria-label="Move down"><ArrowDown className="h-4 w-4" /></Button>
                  <Button type="button" size="sm" variant="ghost" onClick={() => setItems((p) => p.filter((_, i) => i !== idx))} aria-label="Remove item"><Trash2 className="h-4 w-4 text-red-600" /></Button>
                </div>
              )}
            </div>
          ))}
        </div>
      </Card>
    </>
  )
}

/* ===================== 4. Room Content CMS ===================== */
export function CmsRoomsPage() {
  const { can } = useAuth()
  const qc = useQueryClient()
  const [editing, setEditing] = useState<Partial<RoomContentItem> | null>(null)
  const [amenitiesText, setAmenitiesText] = useState('')
  const [err, setErr] = useState<string | null>(null)

  const q = useQuery({
    queryKey: ['admin', 'room-contents'],
    queryFn: async () => (await api.get<{ data: RoomContentItem[] }>('/admin/room-contents')).data.data,
  })

  const saveMut = useMutation({
    mutationFn: async () => {
      if (!editing) return
      const payload = {
        ...editing,
        amenities: amenitiesText.split(',').map((s) => s.trim()).filter(Boolean),
      }
      return editing.id
        ? api.put(`/admin/room-contents/${editing.id}`, payload)
        : api.post('/admin/room-contents', payload)
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin', 'room-contents'] })
      qc.invalidateQueries({ queryKey: ['public'] })
      setEditing(null); setErr(null)
    },
    onError: (e) => setErr(errorMessage(e)),
  })

  const openEditor = (r?: RoomContentItem) => {
    setErr(null)
    if (r) {
      setEditing(r)
      setAmenitiesText((r.amenities ?? []).join(', '))
    } else {
      setEditing({ title: '', slug: '', subtitle: '', summary: '', description: '', occupancy_label: '', bed_configuration: '', indicative_rate: null, status: 'draft', is_featured: true, sort_order: 0, featured_media_id: null })
      setAmenitiesText('')
    }
  }

  return (
    <>
      <PageHeader
        title="Room Content (Website)"
        description="Manage room categories and descriptions displayed on the public website. Operational room inventory & live reservations will be added in Phase 3."
        actions={can('website.create') && <Button onClick={() => openEditor()}><Plus className="h-4 w-4" /> Add Room Category</Button>}
      />
      <Card>
        <DataTable
          columns={[
            { key: 'title', header: 'Room Category', cell: (r) => <div><p className="font-medium text-slate-900">{r.title}</p><p className="text-xs text-slate-500">/rooms/{r.slug}</p></div> },
            { key: 'rate', header: 'Indicative Rate', cell: (r) => formatCurrency(r.indicative_rate) },
            { key: 'status', header: 'Status', cell: (r) => <Badge tone={statusTone[r.status]}>{r.status}</Badge> },
            { key: 'actions', header: '', className: 'text-right', cell: (r) => <Button size="sm" variant="secondary" onClick={() => openEditor(r)}>Edit</Button> },
          ]}
          rows={q.data}
          rowKey={(r) => r.id}
          isLoading={q.isLoading}
          empty={<EmptyState title="No room categories created yet" description="Add verified room categories when ready to publish on the public website." />}
        />
      </Card>

      <Modal open={!!editing} onOpenChange={(o) => !o && setEditing(null)} title={editing?.id ? 'Edit Room Content' : 'New Room Category'} wide>
        {editing && (
          <form onSubmit={(e) => { e.preventDefault(); saveMut.mutate() }} className="space-y-4">
            {err && <Alert variant="error">{err}</Alert>}
            <div className="grid gap-4 sm:grid-cols-2">
              <FormField id="rc-title" label="Title">
                <Input id="rc-title" required value={editing.title ?? ''} onChange={(e) => setEditing({ ...editing, title: e.target.value, slug: editing.id ? editing.slug : e.target.value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') })} />
              </FormField>
              <FormField id="rc-slug" label="URL Slug">
                <Input id="rc-slug" required value={editing.slug ?? ''} onChange={(e) => setEditing({ ...editing, slug: e.target.value })} />
              </FormField>
              <FormField id="rc-occ" label="Occupancy Label (e.g. Up to 2 Guests)">
                <Input id="rc-occ" value={editing.occupancy_label ?? ''} onChange={(e) => setEditing({ ...editing, occupancy_label: e.target.value })} />
              </FormField>
              <FormField id="rc-bed" label="Bed Configuration">
                <Input id="rc-bed" value={editing.bed_configuration ?? ''} onChange={(e) => setEditing({ ...editing, bed_configuration: e.target.value })} />
              </FormField>
              <FormField id="rc-rate" label="Indicative Nightly Rate (NGN, leave blank if on inquiry)">
                <Input id="rc-rate" type="number" min="0" step="0.01" value={editing.indicative_rate ?? ''} onChange={(e) => setEditing({ ...editing, indicative_rate: e.target.value === '' ? null : Number(e.target.value) })} />
              </FormField>
              <FormField id="rc-status" label="Status">
                <Select id="rc-status" disabled={!can('website.publish')} value={editing.status ?? 'draft'} onChange={(e) => setEditing({ ...editing, status: e.target.value as RoomContentItem['status'] })}>
                  <option value="draft">Draft</option>
                  <option value="published">Published</option>
                  <option value="archived">Archived</option>
                </Select>
              </FormField>
            </div>
            <FormField id="rc-sum" label="Short Summary">
              <Textarea id="rc-sum" rows={2} value={editing.summary ?? ''} onChange={(e) => setEditing({ ...editing, summary: e.target.value })} />
            </FormField>
            <FormField id="rc-desc" label="Detailed Description (HTML sanitized)">
              <Textarea id="rc-desc" rows={4} value={editing.description ?? ''} onChange={(e) => setEditing({ ...editing, description: e.target.value })} />
            </FormField>
            <FormField id="rc-am" label="Amenities (comma-separated)">
              <Input id="rc-am" placeholder="Air conditioning, Work desk, En-suite bathroom" value={amenitiesText} onChange={(e) => setAmenitiesText(e.target.value)} />
            </FormField>
            <MediaPickerField label="Featured Room Photo" value={editing.featured_media_id ?? null} onChange={(id) => setEditing({ ...editing, featured_media_id: id })} />
            <div className="flex justify-end gap-2">
              <Button type="button" variant="secondary" onClick={() => setEditing(null)}>Cancel</Button>
              <Button type="submit" loading={saveMut.isPending}>Save Room Content</Button>
            </div>
          </form>
        )}
      </Modal>
    </>
  )
}

/* ===================== 5. Offers CMS ===================== */
export function CmsOffersPage() {
  const { can } = useAuth()
  const qc = useQueryClient()
  const [editing, setEditing] = useState<Partial<OfferItem> | null>(null)
  const [err, setErr] = useState<string | null>(null)

  const q = useQuery({
    queryKey: ['admin', 'offers'],
    queryFn: async () => (await api.get<{ data: OfferItem[] }>('/admin/offers')).data.data,
  })

  const saveMut = useMutation({
    mutationFn: async () => {
      if (!editing) return
      return editing.id ? api.put(`/admin/offers/${editing.id}`, editing) : api.post('/admin/offers', editing)
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin', 'offers'] })
      qc.invalidateQueries({ queryKey: ['public'] })
      setEditing(null); setErr(null)
    },
    onError: (e) => setErr(errorMessage(e)),
  })

  return (
    <>
      <PageHeader
        title="Special Offers"
        description="Create and publish promotional offers for the public website. Unpublished/draft offers are never exposed publicly."
        actions={can('website.create') && (
          <Button onClick={() => { setErr(null); setEditing({ title: '', slug: '', summary: '', body: '', badge_text: 'Special Offer', cta_label: 'Inquire Now', cta_url: '/contact', status: 'draft', is_featured: true, sort_order: 0, featured_media_id: null }) }}>
            <Plus className="h-4 w-4" /> New Offer
          </Button>
        )}
      />
      <Card>
        <DataTable
          columns={[
            { key: 'title', header: 'Offer', cell: (o) => <div><p className="font-medium text-slate-900">{o.title}</p><p className="text-xs text-slate-500">/offers/{o.slug}</p></div> },
            { key: 'badge', header: 'Badge', cell: (o) => o.badge_text ? <Badge tone="brand">{o.badge_text}</Badge> : '—' },
            { key: 'status', header: 'Status', cell: (o) => <Badge tone={statusTone[o.status]}>{o.status}</Badge> },
            { key: 'actions', header: '', className: 'text-right', cell: (o) => <Button size="sm" variant="secondary" onClick={() => { setErr(null); setEditing(o) }}>Edit</Button> },
          ]}
          rows={q.data}
          rowKey={(o) => o.id}
          isLoading={q.isLoading}
          empty={<EmptyState title="No offers created yet" />}
        />
      </Card>

      <Modal open={!!editing} onOpenChange={(o) => !o && setEditing(null)} title={editing?.id ? 'Edit Offer' : 'New Offer'} wide>
        {editing && (
          <form onSubmit={(e) => { e.preventDefault(); saveMut.mutate() }} className="space-y-4">
            {err && <Alert variant="error">{err}</Alert>}
            <div className="grid gap-4 sm:grid-cols-2">
              <FormField id="of-title" label="Offer Title">
                <Input id="of-title" required value={editing.title ?? ''} onChange={(e) => setEditing({ ...editing, title: e.target.value, slug: editing.id ? editing.slug : e.target.value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') })} />
              </FormField>
              <FormField id="of-slug" label="URL Slug">
                <Input id="of-slug" required value={editing.slug ?? ''} onChange={(e) => setEditing({ ...editing, slug: e.target.value })} />
              </FormField>
              <FormField id="of-badge" label="Badge Text">
                <Input id="of-badge" value={editing.badge_text ?? ''} onChange={(e) => setEditing({ ...editing, badge_text: e.target.value })} />
              </FormField>
              <FormField id="of-status" label="Status">
                <Select id="of-status" disabled={!can('website.publish')} value={editing.status ?? 'draft'} onChange={(e) => setEditing({ ...editing, status: e.target.value as OfferItem['status'] })}>
                  <option value="draft">Draft</option>
                  <option value="published">Published</option>
                  <option value="archived">Archived</option>
                </Select>
              </FormField>
            </div>
            <FormField id="of-sum" label="Summary">
              <Textarea id="of-sum" rows={2} value={editing.summary ?? ''} onChange={(e) => setEditing({ ...editing, summary: e.target.value })} />
            </FormField>
            <FormField id="of-body" label="Full Offer Details (HTML sanitized)">
              <Textarea id="of-body" rows={4} value={editing.body ?? ''} onChange={(e) => setEditing({ ...editing, body: e.target.value })} />
            </FormField>
            <FormField id="of-terms" label="Terms & Conditions">
              <Textarea id="of-terms" rows={2} value={editing.terms_text ?? ''} onChange={(e) => setEditing({ ...editing, terms_text: e.target.value })} />
            </FormField>
            <MediaPickerField label="Offer Image" value={editing.featured_media_id ?? null} onChange={(id) => setEditing({ ...editing, featured_media_id: id })} />
            <div className="flex justify-end gap-2">
              <Button type="button" variant="secondary" onClick={() => setEditing(null)}>Cancel</Button>
              <Button type="submit" loading={saveMut.isPending}>Save Offer</Button>
            </div>
          </form>
        )}
      </Modal>
    </>
  )
}

/* ===================== 6. Testimonials CMS ===================== */
export function CmsTestimonialsPage() {
  const { can } = useAuth()
  const qc = useQueryClient()
  const [editing, setEditing] = useState<Partial<TestimonialItem> | null>(null)
  const [err, setErr] = useState<string | null>(null)

  const q = useQuery({
    queryKey: ['admin', 'testimonials'],
    queryFn: async () => (await api.get<{ data: TestimonialItem[] }>('/admin/testimonials')).data.data,
  })

  const saveMut = useMutation({
    mutationFn: async () => {
      if (!editing) return
      return editing.id ? api.put(`/admin/testimonials/${editing.id}`, editing) : api.post('/admin/testimonials', editing)
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin', 'testimonials'] })
      qc.invalidateQueries({ queryKey: ['public'] })
      setEditing(null); setErr(null)
    },
    onError: (e) => setErr(errorMessage(e)),
  })

  return (
    <>
      <PageHeader
        title="Guest Testimonials"
        description="Manage verified guest feedback. Only real, approved testimonials should be published."
        actions={can('website.create') && (
          <Button onClick={() => { setErr(null); setEditing({ guest_name: '', guest_title: '', content: '', rating: 5, status: 'draft', is_featured: true, sort_order: 0 }) }}>
            <Plus className="h-4 w-4" /> Add Testimonial
          </Button>
        )}
      />
      <Card>
        <DataTable
          columns={[
            { key: 'guest', header: 'Guest', cell: (t) => <div><p className="font-medium text-slate-900">{t.guest_name}</p><p className="text-xs text-slate-500">{t.guest_title}</p></div> },
            { key: 'content', header: 'Feedback', cell: (t) => <p className="max-w-md truncate text-sm text-slate-600">{t.content}</p> },
            { key: 'status', header: 'Status', cell: (t) => <Badge tone={statusTone[t.status]}>{t.status}</Badge> },
            { key: 'actions', header: '', className: 'text-right', cell: (t) => <Button size="sm" variant="secondary" onClick={() => { setErr(null); setEditing(t) }}>Edit</Button> },
          ]}
          rows={q.data}
          rowKey={(t) => t.id}
          isLoading={q.isLoading}
          empty={<EmptyState title="No testimonials recorded yet" description="Never publish fabricated reviews. Add verified guest testimonials here as they are received." />}
        />
      </Card>

      <Modal open={!!editing} onOpenChange={(o) => !o && setEditing(null)} title={editing?.id ? 'Edit Testimonial' : 'Add Testimonial'}>
        {editing && (
          <form onSubmit={(e) => { e.preventDefault(); saveMut.mutate() }} className="space-y-4">
            {err && <Alert variant="error">{err}</Alert>}
            <FormField id="tm-name" label="Guest Name">
              <Input id="tm-name" required value={editing.guest_name ?? ''} onChange={(e) => setEditing({ ...editing, guest_name: e.target.value })} />
            </FormField>
            <FormField id="tm-title" label="Guest Context / Title (optional)">
              <Input id="tm-title" value={editing.guest_title ?? ''} onChange={(e) => setEditing({ ...editing, guest_title: e.target.value })} />
            </FormField>
            <FormField id="tm-content" label="Feedback">
              <Textarea id="tm-content" rows={4} required value={editing.content ?? ''} onChange={(e) => setEditing({ ...editing, content: e.target.value })} />
            </FormField>
            <div className="grid grid-cols-2 gap-3">
              <FormField id="tm-rating" label="Rating (1–5)">
                <Select id="tm-rating" value={editing.rating ?? 5} onChange={(e) => setEditing({ ...editing, rating: Number(e.target.value) })}>
                  {[5, 4, 3, 2, 1].map((n) => <option key={n} value={n}>{n} Stars</option>)}
                </Select>
              </FormField>
              <FormField id="tm-status" label="Status">
                <Select id="tm-status" disabled={!can('website.publish')} value={editing.status ?? 'draft'} onChange={(e) => setEditing({ ...editing, status: e.target.value as TestimonialItem['status'] })}>
                  <option value="draft">Draft</option>
                  <option value="published">Published</option>
                  <option value="archived">Archived</option>
                </Select>
              </FormField>
            </div>
            <div className="flex justify-end gap-2">
              <Button type="button" variant="secondary" onClick={() => setEditing(null)}>Cancel</Button>
              <Button type="submit" loading={saveMut.isPending}>Save</Button>
            </div>
          </form>
        )}
      </Modal>
    </>
  )
}

/* ===================== 7. Website & Homepage Settings ===================== */
type WebsiteSettingsResponse = {
  data: {
    website: PublicBootstrap['settings']['website']
  }
  inquiries_count: number
  recent_inquiries: Array<{ id: number; name: string; email: string; phone: string | null; subject: string; message: string; created_at: string }>
}

export function CmsWebsiteSettingsPage() {
  const { can } = useAuth()
  const qc = useQueryClient()
  const editable = can('website.settings')
  const q = useQuery({
    queryKey: ['admin', 'website-settings'],
    queryFn: async () => (await api.get<WebsiteSettingsResponse>('/admin/website-settings')).data,
  })
  const [ws, setWs] = useState<PublicBootstrap['settings']['website'] | null>(null)
  const [msg, setMsg] = useState<{ t: 'success' | 'error'; m: string } | null>(null)

  useEffect(() => {
    // oxlint-disable-next-line react/set-state-in-effect -- This initializes the editable settings draft after it is fetched.
    if (q.data) setWs(q.data.data.website)
  }, [q.data])

  const saveMut = useMutation({
    mutationFn: async () => api.put('/admin/website-settings', { website: ws }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin', 'website-settings'] })
      qc.invalidateQueries({ queryKey: ['public'] })
      setMsg({ t: 'success', m: 'Website settings saved.' })
    },
    onError: (e) => setMsg({ t: 'error', m: errorMessage(e) }),
  })

  if (q.isLoading || !ws) return <Spinner />
  if (q.isError) return <ErrorState message={errorMessage(q.error)} onRetry={() => q.refetch()} />

  return (
    <div className="space-y-6">
      <PageHeader
        title="Website & Homepage Settings"
        description="Configure homepage hero content, section visibility, default SEO metadata, and view public contact inquiries."
        actions={editable && <Button loading={saveMut.isPending} onClick={() => saveMut.mutate()}>Save Website Settings</Button>}
      />
      {msg && <Alert variant={msg.t}>{msg.m}</Alert>}

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader title="Homepage Hero & Visibility" />
          <div className="space-y-4 p-5">
            <FormField id="ws-tagline" label="Header Tagline">
              <Input id="ws-tagline" disabled={!editable} value={ws.tagline ?? ''} onChange={(e) => setWs({ ...ws, tagline: e.target.value })} />
            </FormField>
            <FormField id="ws-htitle" label="Homepage Hero Title">
              <Input id="ws-htitle" disabled={!editable} value={ws.hero_title ?? ''} onChange={(e) => setWs({ ...ws, hero_title: e.target.value })} />
            </FormField>
            <FormField id="ws-hsub" label="Homepage Hero Subtitle">
              <Input id="ws-hsub" disabled={!editable} value={ws.hero_subtitle ?? ''} onChange={(e) => setWs({ ...ws, hero_subtitle: e.target.value })} />
            </FormField>
            <div className="grid grid-cols-2 gap-3">
              <FormField id="ws-hctal" label="Hero CTA Button Label">
                <Input id="ws-hctal" disabled={!editable} value={ws.hero_cta_label ?? ''} onChange={(e) => setWs({ ...ws, hero_cta_label: e.target.value })} />
              </FormField>
              <FormField id="ws-hctau" label="Hero CTA Button URL">
                <Input id="ws-hctau" disabled={!editable} value={ws.hero_cta_url ?? ''} onChange={(e) => setWs({ ...ws, hero_cta_url: e.target.value })} />
              </FormField>
            </div>
            <MediaPickerField label="Homepage Hero Background Image" value={ws.hero_media_id} onChange={(id) => setWs({ ...ws, hero_media_id: id })} disabled={!editable} />
            <div className="space-y-2 pt-2 text-sm text-slate-700">
              <label className="flex items-center gap-2"><input type="checkbox" disabled={!editable} checked={ws.show_featured_rooms} onChange={(e) => setWs({ ...ws, show_featured_rooms: e.target.checked })} /> Show Featured Rooms on Homepage</label>
              <label className="flex items-center gap-2"><input type="checkbox" disabled={!editable} checked={ws.show_featured_offers} onChange={(e) => setWs({ ...ws, show_featured_offers: e.target.checked })} /> Show Special Offers on Homepage</label>
              <label className="flex items-center gap-2"><input type="checkbox" disabled={!editable} checked={ws.show_gallery_preview} onChange={(e) => setWs({ ...ws, show_gallery_preview: e.target.checked })} /> Show Gallery Preview on Homepage</label>
              <label className="flex items-center gap-2"><input type="checkbox" disabled={!editable} checked={ws.show_testimonials} onChange={(e) => setWs({ ...ws, show_testimonials: e.target.checked })} /> Show Published Testimonials on Homepage</label>
            </div>
          </div>
        </Card>

        <div className="space-y-6">
          <Card>
            <CardHeader title="Default SEO & Maintenance Mode" />
            <div className="space-y-4 p-5">
              <FormField id="ws-seot" label="Default Website Title">
                <Input id="ws-seot" disabled={!editable} value={ws.seo_default_title ?? ''} onChange={(e) => setWs({ ...ws, seo_default_title: e.target.value })} />
              </FormField>
              <FormField id="ws-seod" label="Default Meta Description">
                <Textarea id="ws-seod" rows={3} disabled={!editable} value={ws.seo_default_description ?? ''} onChange={(e) => setWs({ ...ws, seo_default_description: e.target.value })} />
              </FormField>
              <FormField id="ws-fnotice" label="Footer Notice">
                <Input id="ws-fnotice" disabled={!editable} value={ws.footer_notice ?? ''} onChange={(e) => setWs({ ...ws, footer_notice: e.target.value })} />
              </FormField>
              <label className="flex items-center gap-2 text-sm font-medium text-amber-800">
                <input type="checkbox" disabled={!editable} checked={ws.maintenance_mode} onChange={(e) => setWs({ ...ws, maintenance_mode: e.target.checked })} />
                Enable Maintenance Notice on Public Website
              </label>
            </div>
          </Card>

          <Card>
            <CardHeader title={`Recent Contact Inquiries (${q.data?.inquiries_count ?? 0})`} description="Submitted via the public Contact Us page." />
            {!q.data?.recent_inquiries.length ? (
              <EmptyState title="No contact inquiries yet" />
            ) : (
              <ul className="divide-y divide-slate-100 text-sm">
                {q.data.recent_inquiries.slice(0, 6).map((inq) => (
                  <li key={inq.id} className="p-4">
                    <div className="flex justify-between gap-2">
                      <span className="font-semibold text-slate-900">{inq.subject}</span>
                      <span className="text-xs text-slate-500">{formatDateTime(inq.created_at)}</span>
                    </div>
                    <p className="text-xs text-slate-500">{inq.name} · {inq.email} {inq.phone ? `· ${inq.phone}` : ''}</p>
                    <p className="mt-1 text-slate-700">{inq.message}</p>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>
      </div>
    </div>
  )
}
