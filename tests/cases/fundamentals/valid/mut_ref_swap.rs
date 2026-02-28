// stdout: 20
// stdout: 10
fn swap(a: &mut i32, b: &mut i32) {
    let temp: i32 = *a;
    *a = *b;
    *b = temp;
}

fn main() {
    let mut x: i32 = 10;
    let mut y: i32 = 20;
    swap(&mut x, &mut y);
    println!("{}", x);
    println!("{}", y);
}
