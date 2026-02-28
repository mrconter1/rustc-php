// exit: 0
// stdout: 20
// stdout: 10

fn swap<T>(a: &mut T, b: &mut T) {
    let tmp = *a;
    *a = *b;
    *b = tmp;
}

fn main() {
    let mut x = 10;
    let mut y = 20;
    swap(&mut x, &mut y);
    println!("{}", x);
    println!("{}", y);
}
