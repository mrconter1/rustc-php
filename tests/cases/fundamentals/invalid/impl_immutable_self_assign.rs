// error: Cannot assign to field of immutable variable 'self'

struct Foo {
    x: i32,
    y: i32,
}

impl Foo {
    fn try_set(&self, val: i32) {
        self.x = val;
    }
}

fn main() {
    let f = Foo { x: 1, y: 2 };
    f.try_set(10);
}
